<?php
/*******************************************************************************

    Copyright 2016 Whole Foods Co-op

    This file is part of IT CORE.

    IT CORE is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    IT CORE is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    in the file license.txt along with IT CORE; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA

*********************************************************************************/

namespace COREPOS\pos\lib;
use COREPOS\pos\lib\CoreState;
use COREPOS\pos\lib\Database;
use COREPOS\pos\lib\DiscountModule;
use COREPOS\pos\lib\DisplayLib;
use COREPOS\pos\lib\MiscLib;
use COREPOS\pos\lib\PrehLib;
use COREPOS\pos\lib\TransRecord;

/**
  @class MemberLib
*/
class MemberLib 
{
    /**
      Remove member number from current transaction
    */
    static public function clear()
    {
        CoreState::memberReset();
        $dbc = Database::tDataConnect();
        $dbc->query("UPDATE localtemptrans SET card_no=0,percentDiscount=NULL");
        \CoreLocal::set("ttlflag",0);    
        $opts = array('upc'=>'DEL_MEMENTRY');
        TransRecord::add_log_record($opts);
    }

    /**
      Begin setting a member number for a transaction
      @param $member_number CardNo from custdata
      @return An array. See Parser::default_json()
       for format.

      This function will either assign the number
      to the current transaction or return a redirect
      request to get more input. If you want the
      cashier to verify member name from a list, use
      this function. If you want to force the number
      to be set immediately, use setMember().
    */
    static public function memberID($member_number) 
    { 
        $query = "
            SELECT CardNo,
                personNum
            FROM custdata
            WHERE CardNo=" . ((int)$member_number);

        $ret = array(
            "main_frame"=>false,
            "output"=>"",
            "target"=>".baseHeight",
            "redraw_footer"=>false
        );
        
        $dbc = Database::pDataConnect();
        $result = $dbc->query($query);
        $num_rows = $dbc->num_rows($result);

        /**
          If only a single record exists for the member number,
          the member will be set immediately if:
          - the account is the designated, catchall non-member
          - the verifyName setting is disabled
        */
        if ($num_rows == 1) {
            if ($member_number == \CoreLocal::get("defaultNonMem") || \CoreLocal::get('verifyName') != 1) {
                $row = $dbc->fetch_row($result);
                self::setMember($row["CardNo"], $row["personNum"]);
                $ret['redraw_footer'] = true;
                $ret['output'] = DisplayLib::lastpage();

                if ($member_number != \CoreLocal::get('defaultNonMem')) {
                    $ret['udpmsg'] = 'goodBeep';
                }

                return $ret;
            }
        }

        /**
          Go to member search page in all other cases.
          If zero matching records are found, member search should be next.
          If multiple records are found, picking the correct name should
          be next.
          If verifyName is enabled, confirming the name should be next.
        */
        $ret['main_frame'] = MiscLib::base_url() . "gui-modules/memlist.php?idSearch=" . $member_number;

        return $ret;
    }

    /**
      Assign store-specific alternate member message line
      @param $store code for the coop
      @param $member CardNo from custdata
      @param $personNumber personNum from custdata
      @param $row a record from custdata
      @param $chargeOk whether member can store-charge purchases
    */
    // @hintable
    static public function setAltMemMsg($store, $member, $personNumber, $row)
    {
        if ($store == 'WEFC_Toronto') {
            $chargeOk = self::chargeOk();
            /* Doesn't quite allow for StoreCharge/PrePay for regular members
             * either instead of or in addition to CoopCred
             */
            if (isset($row['blueLine'])) {
                $memMsg = $row['blueLine'];
            } else {
                $memMsg = '#'.$member;
            }
            if ($member == \CoreLocal::get('defaultNonMem')) {
                \CoreLocal::set("memMsg", $memMsg);
                return;
            }

            if ($member < 99000) {

                if (in_array('CoopCred', \CoreLocal::get('PluginList'))) {
                    $conn = \CoopCredLib::ccDataConnect();
                    if ($conn !== False) {
                        $ccQ = "SELECT p.programID AS ppID, p.programName, p.tenderType,
                            p.active, p.startDate, p.endDate,
                            p.bankID, p.creditOK, p.inputOK, p.transferOK,
                            m.creditBalance, m.creditLimit, m.creditOK, m.inputOK,
                                m.transferOK, m.isBank
                                ,(m.creditLimit - m.creditBalance) as availCreditBalance
                            FROM CCredMemberships m
                            JOIN CCredPrograms p
                                ON p.programID = m.programID
                                WHERE m.cardNo =?" .
                            " ORDER BY ppID";
                        $ccS = $conn->prepare("$ccQ");
                        if ($ccS === False) {
                            \CoreLocal::set("memMsg", $memMsg . "Prep failed");
                            return;
                        }
                        $args = array();
                        $args[] = $member;
                        $ccR = $conn->execute($ccS, $args);
                        if ($ccR === False) {
                            \CoreLocal::set("memMsg", $memMsg . "Query failed");
                            return;
                        }
                        if ($conn->num_rows($ccR) == 0) {
                            \CoreLocal::set("memMsg", $memMsg);
                            return;
                        }

                        $message = "";
                        while ($row = $conn->fetchRow($ccR)) {
                            $programOK = \CoopCredLib::programOK($row['tenderType'], $conn);
                            if ($programOK === True) {
                                $programCode = 'CCred' . \CoreLocal::get("CCredProgramID");
                                $tenderKeyCap = (\CoreLocal::get("{$programCode}tenderKeyCap") != "")
                                    ?  \CoreLocal::get("{$programCode}tenderKeyCap")
                                    : 'CCr' . \CoreLocal::get("CCredProgramID");
                                $programBalance =
                                    (\CoreLocal::get("{$programCode}availBal")) ?
                                    \CoreLocal::get("{$programCode}availBal") :
                                    \CoreLocal::get("{$programCode}availCreditBalance");

                                $message .= " {$tenderKeyCap}: " .  number_format($programBalance,2);
                            }
                            else {
                                $message .= $row['tenderType'] . " not OK";
                            }
                        }
                        if ($message != "") {
                            \CoreLocal::set("memMsg", $memMsg . "$message");
                            return;
                        }

                    }
                }

                if ($chargeOk == 1) {
                    $conn = Database::pDataConnect();
                    $query = "SELECT ChargeLimit AS CLimit
                        FROM custdata
                        WHERE personNum=1 AND CardNo = $member";
                    if (\CoreLocal::get('NoCompat') == 1) {
                        $query = str_replace('ChargeLimit', 'MemDiscountLimit', $query);
                    } else {
                        $table_def = $conn->tableDefinition('custdata');
                        // 3Jan14 schema may not have been updated
                        if (!isset($table_def['ChargeLimit'])) {
                            $query = str_replace('ChargeLimit', 'MemDiscountLimit', $query);
                        }
                    }
                    $result = $conn->query($query);
                    $num_rows = $conn->num_rows($result);
                    if ($num_rows > 0) {
                        $row2 = $conn->fetchRow($result);
                    } else {
                        $row2 = array();
                    }

                    if (isset($row2['CLimit'])) {
                        $limit = 1.00 * $row2['CLimit'];
                    } else {
                        $limit = 0.00;
                    }

                    // Prepay
                    if ($limit == 0.00) {
                        \CoreLocal::set("memMsg", $memMsg . _(' : Pre Pay: $') .
                            number_format(((float)CoreLocal::get("availBal") * 1),2)
                        );
                    // Store Charge
                    } else {
                        \CoreLocal::set("memMsg", $memMsg . _(' : Store Charge: $') .
                            number_format(((float)CoreLocal::get("availBal") * 1),2)
                        );
                    }
                }

            // Intra-coop transfer
            } else {
                \CoreLocal::set("memMsg", $memMsg);
                \CoreLocal::set("memMsg", $memMsg . _(' : Intra Coop spent: $') .
                   number_format(((float)CoreLocal::get("balance") * 1),2)
                );
            }
        // WEFC_Toronto
        }
    }

    static private function defaultMemMsg($member, $row)
    {
        /**
          Determine what string is shown in the upper
          left of the screen to indicate the current member
        */
        $memMsg = '#' . $member;
        if (!empty($row['blueLine'])) {
            $memMsg = $row['blueLine'];
        }
        $chargeOk = self::chargeOk();
        if (\CoreLocal::get("balance") != 0 && $member != \CoreLocal::get("defaultNonMem")) {
            $memMsg .= _(" AR");
        }
        if (\CoreLocal::get("SSI") == 1) {
            $memMsg .= " #";
        }
        $conn = Database::pDataConnect();
        if (\CoreLocal::get('NoCompat') == 1 || $conn->tableExists('CustomerNotifications')) {
            $blQ = '
                SELECT message
                FROM CustomerNotifications
                WHERE cardNo=' . ((int)$member) . '
                    AND type=\'blueline\'
                ORDER BY message';
            $blR = $conn->query($blQ);
            while ($blW = $conn->fetchRow($blR)) {
                $memMsg .= ' ' . $blW['message'];
            }
        }

        return $memMsg;
    }

    /**
      Assign a member number to a transaction
      @param $member CardNo from custdata
      @param $personNumber personNum from custdata

      See memberID() for more information.
    */
    static public function setMember($member, $personNumber, $row=array())
    {
        $conn = Database::pDataConnect();

        /**
          Look up the member information here. There's no good 
          reason to have calling code pass in a specially formatted
          row of data
        */
        $query = "
            SELECT 
                CardNo,
                personNum,
                LastName,
                FirstName,
                CashBack,
                Balance,
                Discount,
                ChargeOk,
                WriteChecks,
                StoreCoupons,
                Type,
                memType,
                staff,
                SSI,
                Purchases,
                NumberOfChecks,
                memCoupons,
                blueLine,
                Shown,
                id 
            FROM custdata 
            WHERE CardNo = " . ((int)$member) . "
                AND personNum = " . ((int)$personNumber);
        $result = $conn->query($query);
        $row = $conn->fetch_row($result);

        \CoreLocal::set("memberID",$member);
        \CoreLocal::set("memType",$row["memType"]);
        \CoreLocal::set("lname",$row["LastName"]);
        \CoreLocal::set("fname",$row["FirstName"]);
        \CoreLocal::set("Type",$row["Type"]);
        \CoreLocal::set("isStaff",$row["staff"]);
        \CoreLocal::set("SSI",$row["SSI"]);
        if (\CoreLocal::get("Type") == "PC") {
            \CoreLocal::set("isMember",1);
        } else {
            \CoreLocal::set("isMember",0);
        }

        /**
          Optinonally use memtype table to normalize attributes
          by member type
        */
        if (\CoreLocal::get('useMemTypeTable') == 1 && (\CoreLocal::get('NoCompat') == 1 || $conn->table_exists('memtype'))) {
            $prep = $conn->prepare('SELECT discount, staff, ssi 
                                    FROM memtype
                                    WHERE memtype=?');
            $res = $conn->execute($prep, array((int)CoreLocal::get('memType')));
            if ($conn->num_rows($res) > 0) {
                $mt_row = $conn->fetch_row($res);
                $row['Discount'] = $mt_row['discount'];
                \CoreLocal::set('isStaff', $mt_row['staff']);
                \CoreLocal::set('SSI', $mt_row['ssi']);
            }
        }
        if (\CoreLocal::get("isStaff") == 0) {
            \CoreLocal::set("staffSpecial", 0);
        }

        \CoreLocal::set("memMsg", self::defaultMemMsg($member, $row));
        self::setAltMemMsg(\CoreLocal::get("store"), $member, $personNumber, $row);

        /**
          Set member number and attributes
          in the current transaction
        */
        $conn2 = Database::tDataConnect();
        $memquery = "
            UPDATE localtemptrans 
            SET card_no = '" . $member . "',
                memType = " . sprintf("%d",\CoreLocal::get("memType")) . ",
                staff = " . sprintf("%d",\CoreLocal::get("isStaff"));
        $conn2->query($memquery);

        /**
          Add the member discount
        */
        if (\CoreLocal::get('discountEnforced')) {
            // skip subtotaling automatically since that occurs farther down
            DiscountModule::updateDiscount(new DiscountModule($row['Discount'], 'custdata'), false);
        }

        /**
          Log the member entry
        */
        \CoreLocal::set("memberID",$member);
        $opts = array('upc'=>'MEMENTRY','description'=>'CARDNO IN NUMFLAG','numflag'=>$member);
        TransRecord::add_log_record($opts);

        /**
          Optionally add a subtotal line depending
          on member_subtotal setting.
        */
        if (\CoreLocal::get('member_subtotal') === 0 || \CoreLocal::get('member_subtotal') === '0') {
            $noop = "";
        } else {
            PrehLib::ttl();
        } 
    }

    /**
      Check if the member has overdue store charge balance
      @param $cardno member number
      @return True or False

      The logic for what constitutes past due has to be built
      into the unpaid_ar_today view. Without that this function
      doesn't really do much.
    */
    static public function checkUnpaidAR($cardno)
    {
        // only attempt if server is available
        // and not the default non-member
        if ($cardno == \CoreLocal::get("defaultNonMem")) return false;
        if (\CoreLocal::get("balance") == 0) return false;

        $dbc = Database::mDataConnect();

        if (\CoreLocal::get('NoCompat') != 1 && !$dbc->table_exists("unpaid_ar_today")) return false;

        $query = "SELECT old_balance,recent_payments FROM unpaid_ar_today
            WHERE card_no = $cardno";
        $result = $dbc->query($query);

        // should always be a row, but just in case
        if ($dbc->num_rows($result) == 0) return false;
        $row = $dbc->fetch_row($result);

        $bal = $row["old_balance"];
        $paid = $row["recent_payments"];
        if (\CoreLocal::get("memChargeTotal") > 0) {
            $paid += \CoreLocal::get("memChargeTotal");
        }
        
        if ($bal <= 0) return false;
        if ($paid >= $bal) return false;

        // only case where customer prompt should appear
        if ($bal > 0 && $paid < $bal){
            \CoreLocal::set("old_ar_balance",$bal - $paid);
            return true;
        }

        // just in case i forgot anything...
        return false;
    }

    /**
      Check whether the current member has store
      charge balance available.
      @return
       1 - Yes
       0 - No

      Sets current balance in session as "balance".
      Sets available balance in session as "availBal".
    */
    static public function chargeOk() 
    {
        $conn = Database::pDataConnect();
        $query = "SELECT c.ChargeLimit - c.Balance AS availBal,
            c.Balance, c.ChargeOk
            FROM custdata AS c 
            WHERE c.personNum=1 AND c.CardNo = " . ((int)\CoreLocal::get("memberID"));
        if (\CoreLocal::get('NoCompat') == 1) {
            $query = str_replace('c.ChargeLimit', 'c.MemDiscountLimit', $query);
        } else {
            $table_def = $conn->tableDefinition('custdata');
            // 3Jan14 schema may not have been updated
            if (!isset($table_def['ChargeLimit'])) {
                $query = str_replace('c.ChargeLimit', 'c.MemDiscountLimit', $query);
            }
        }

        $result = $conn->query($query);
        $num_rows = $conn->num_rows($result);
        $row = $conn->fetchRow($result);

        $availBal = $row["availBal"] + \CoreLocal::get("memChargeTotal");
        
        \CoreLocal::set("balance",$row["Balance"]);
        \CoreLocal::set("availBal",number_format($availBal,2,'.',''));    
        
        return ($num_rows == 0 || !$row['ChargeOk']) ? 0 : 1;
    }

    static public function getChgName() 
    {
        $query = "select LastName, FirstName from custdata where CardNo = '" .\CoreLocal::get("memberID") ."'";
        $connection = Database::pDataConnect();
        $result = $connection->query($query);
        $num_rows = $connection->num_rows($result);

        if ($num_rows > 0) {
            $LastInit = substr(\CoreLocal::get("lname"), 0, 1).".";
            return trim(\CoreLocal::get("fname")) ." ". $LastInit;
        } else {
            return \CoreLocal::get('memMsg');
        }
    }

}

