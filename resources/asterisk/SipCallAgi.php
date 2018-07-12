<?php
/**
 *
 */
class SipCallAgi
{

    public function processCall(&$MAGNUS, &$agi, &$CalcAgi)
    {
        if (($MAGNUS->agiconfig['use_dnid'] == 1) && (strlen($MAGNUS->dnid) > 2)) {
            $MAGNUS->destination = $MAGNUS->dnid;
        }

        $MAGNUS->destination = $MAGNUS->dnid;
        $dialparams          = $MAGNUS->agiconfig['dialcommand_param_sipiax_friend'];
        //add the sipaccount dial timeout in dialcommand_param.
        $dialparams = explode(',', $dialparams);
        if (isset($dialparams[1])) {
            $dialparams[1] = $MAGNUS->modelSip->dial_timeout;
        }
        $dialparams = implode(',', $dialparams);

        $sql               = "SELECT * FROM pkg_user WHERE id = " . $MAGNUS->modelSip->id_user . " LIMIT 1";
        $MAGNUS->modelUser = $agi->query($sql)->fetch(PDO::FETCH_OBJ);
        AuthenticateAgi::setMagnusAttrubutes($MAGNUS, $agi, $MAGNUS->modelUser);

        $MAGNUS->startRecordCall($agi);

        $dialstr = "SIP/" . $MAGNUS->destination;
        //check if user are registered in a asterisk slave
        $sql          = "SELECT id FROM pkg_servers WHERE status = 1 AND type = 'asterisk' LIMIT 1";
        $modelServers = $agi->query($sql)->fetch(PDO::FETCH_OBJ);
        if (isset($modelServers->id)) {
            if (strlen($MAGNUS->modelSip->register_server_ip) > 1 && $MAGNUS->modelSip->regseconds < (time() - 7200)) {
                $dialstr .= '@' . $MAGNUS->modelSip->register_server_ip;
            }

        }
        $startCall = time();
        $MAGNUS->run_dial($agi, $dialstr, $dialparams);

        $answeredtime = $agi->get_variable("ANSWEREDTIME");
        $answeredtime = $answeredtime['data'];
        $dialstatus   = $agi->get_variable("DIALSTATUS");
        $dialstatus   = $dialstatus['data'];

        $MAGNUS->stopRecordCall($agi);

        $agi->verbose("[" . $MAGNUS->username . " Friend]:[ANSWEREDTIME=" . $answeredtime . "-DIALSTATUS=" . $dialstatus . "]", 6);

        $sql             = "SELECT * FROM pkg_sip WHERE name = '$MAGNUS->destination' LIMIT 1 ";
        $modelSipForward = $agi->query($sql)->fetch(PDO::FETCH_OBJ);
        if (isset($modelSipForward->id) && strlen($modelSipForward->forward) > 3 && $dialstatus != 'CANCEL' && $dialstatus != 'ANSWER') {

            $this->callForward($MAGNUS, $agi, $CalcAgi, $modelSipForward);
            $MAGNUS->hangup($agi);
        }

        $answeredtime = $MAGNUS->executeVoiceMail($agi, $dialstatus, $answeredtime);

        if (strlen($MAGNUS->dialstatus_rev_list[$dialstatus]) > 0) {
            $terminatecauseid = $MAGNUS->dialstatus_rev_list[$dialstatus];
        } else {
            $terminatecauseid = 0;
        }

        $siptransfer = $agi->get_variable("SIPTRANSFER");
        if ($answeredtime > 0 && $siptransfer['data'] != 'yes' && $terminatecauseid == 1) {
            if ($MAGNUS->config['global']['charge_sip_call'] > 0) {

                $cost = ($MAGNUS->config['global']['charge_sip_call'] / 60) * $answeredtime;
                $sql  = "UPDATE pkg_user SET credit = credit - " . $MAGNUS->round_precision(abs($cost)) . "
                            WHERE id = $MAGNUS->modelUser->id LIMIT 1  ";
                $agi->exec($sql);
                $agi->verbose("Update credit username after transfer $MAGNUS->username, " . $cost, 15);
            } else {
                $cost = 0;
            }
        }

        $MAGNUS->id_trunk          = null;
        $CalcAgi->starttime        = date("Y-m-d H:i:s", $startCall);
        $CalcAgi->sessiontime      = $answeredtime;
        $CalcAgi->terminatecauseid = $terminatecauseid;
        $CalcAgi->sessionbill      = $cost;
        $CalcAgi->sipiax           = 1;
        $CalcAgi->buycost          = 0;
        $CalcAgi->id_prefix        = null;
        $CalcAgi->saveCDR($agi, $MAGNUS);

        $MAGNUS->hangup($agi);
    }

    public function callForward($MAGNUS, $agi, $CalcAgi, $modelSipForward)
    {

        $forward     = explode(("|"), $modelSipForward->forward);
        $optionType  = $forward[0];
        $optionValue = $forward[1];

        if ($optionType == 'sip') // SIP
        {
            $agi->verbose('Sip call', 25);
            $insertCDR = true;
            $sql       = "SELECT name, callerid FROM pkg_sip WHERE id = $optionValue LIMIT 1";
            $modelSip  = $agi->query($sql)->fetch(PDO::FETCH_OBJ);

            $MAGNUS->CallerID = $modelSip->callerid;
            $agi->set_variable("CALLERID(all)", $MAGNUS->CallerID);

            $MAGNUS->dnid = $MAGNUS->destination = $MAGNUS->sip_account = $modelSip->name;
            $sipCallAgi->processCall($MAGNUS, $agi, $CalcAgi);

        } else if ($optionType == 'group') // CUSTOM
        {
            $agi->verbose("Call to group " . $optionValue, 1);
            $sql      = "SELECT * FROM pkg_sip WHERE `group` = '$optionValue'";
            $modelSip = $agi->query($sql)->fetchAll(PDO::FETCH_OBJ);

            if (!isset($modelSip[0]->id)) {
                $agi->verbose('GROUP NOT FOUND');
                $agi->stream_file('prepaid-invalid-digits', '#');
            }
            $MAGNUS->sip_account = $modelSip[0]->name;
            $group               = '';

            foreach ($modelSip as $key => $value) {
                $group .= "SIP/" . $value->name . "&";
            }

            $dialstr = substr($group, 0, -1) . $dialparams;

            $MAGNUS->startRecordCall($agi);
            $agi->set_variable("CALLERID(all)", $MAGNUS->CallerID);
            $MAGNUS->run_dial($agi, $dialstr, $MAGNUS->agiconfig['dialcommand_param_call_2did']);
            $dialstatus = $agi->get_variable("DIALSTATUS");
            $dialstatus = $dialstatus['data'];
        } else if (preg_match("/custom/", $optionType)) // CUSTOM
        {
            $MAGNUS->startRecordCall($agi);
            $agi->set_variable("CALLERID(all)", $MAGNUS->CallerID);
            $MAGNUS->run_dial($agi, $optionValue);
            $dialstatus = $agi->get_variable("DIALSTATUS");
            $dialstatus = $dialstatus['data'];
        } else if ($optionType == 'ivr') // QUEUE
        {
            $didAgi                                = new DidAgi();
            $didAgi->modelDestination[0]['id_ivr'] = $optionValue;
            IvrAgi::callIvr($agi, $MAGNUS, $CalcAgi, $DidAgi, $type);
        } else if ($optionType == 'queue') // QUEUE
        {
            $didAgi                                  = new DidAgi();
            $didAgi->modelDestination[0]['id_queue'] = $optionValue;
            QueueAgi::callQueue($agi, $MAGNUS, $CalcAgi, $DidAgi, $type);
            $dialstatus = $CalcAgi->sessiontime > 0 ? 'ANSWER' : 'DONTCALL';
        } else if (preg_match("/^number/", $optionType)) //envia para um fixo ou celular
        {
            $sql                 = "SELECT * FROM pkg_user WHERE id = $modelSipForward->id_user  LIMIT 1";
            $modelUserForward    = $agi->query($sql)->fetch(PDO::FETCH_OBJ);
            $MAGNUS->accountcode = $modelUserForward->accountcode;
            $agi->verbose("CALL number $optionValue");
            $didAgi = new DidAgi();
            $didAgi->call_did($agi, $MAGNUS, $CalcAgi, $optionValue);
        }
    }
}