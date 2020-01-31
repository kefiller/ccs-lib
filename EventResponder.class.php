<?php

namespace CCS;

use CCS\pbx\PbxEvent;
use CCS\pbx\OriginatedCallsPool;
use CCS\db\MyDB;
use CCS\Logger;

class EventResponder
{
    private static $instance = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    private function __clone()
    {
    }
    private function __construct()
    {
    }

    /** @return void */
    public function respond(PbxEvent $pbxEvt)
    {
        // emit unchanged PBX event
        $pbxEvtKeys = $pbxEvt->getKeys();

        $apiPbxEvt = new Event('pbx.event', $pbxEvtKeys);
        $evtEmitter = EventEmitter::getInstance();
        $evtEmitter->emit($apiPbxEvt);

        $evtKeys = ['srv' => $pbxEvtKeys['srv'] ];

        $origCallsPool = OriginatedCallsPool::getInstance();

        // Check if it's OriginateResponse
        $event = isset($pbxEvtKeys['event'])?$pbxEvtKeys['event']:'';
        if ($event == 'OriginateResponse') {
            $actionId = $pbxEvtKeys['actionid'] ?? '';
            $originatedCall = $origCallsPool->get($actionId);
            if (!$originatedCall) { // no such call in pool
                return;
            }

            $evtKeys['destination'] = $originatedCall->getDestination()->getKeys();
            $evtKeys['bridge-target'] = $originatedCall->getBridgeTarget()->getKeys();
            $evtKeys['id'] = $originatedCall->getId();
            $evtKeys['extra-data'] = $originatedCall->getExtraData();
            $evtKeys['response'] = $pbxEvtKeys['response'];
            $evtKeys['reason'] = $pbxEvtKeys['reason'];

            switch ($evtKeys['reason']) {
                case '0':
                    $evtKeys['reason-desc'] = 'no such extension or number';
                    break;
                case '1':
                    $evtKeys['reason-desc'] = 'no answer';
                    break;
                case '4':
                    $evtKeys['reason-desc'] = 'answered';
                    break;
                case '5':
                    $evtKeys['reason-desc'] = 'busy';
                    break;
                case '8':
                    $evtKeys['reason-desc'] = 'congested or not available';
                    break;
                default:
                    $evtKeys['reason-desc'] = 'unknown';
                    break;
            }

            if ($evtKeys['reason-desc'] == 'answered') {
                // only if call is answered, it has valid uniqueid and we leave it in pool
                // we''ll track it until hangup event with this uniqueid
                $originatedCall->setUniqueid($pbxEvtKeys['uniqueid']);
                $origCallsPool->add($originatedCall);
                $evtKeys['uniqueid'] = $pbxEvtKeys['uniqueid'];
            } else {
                // else call is dropped and we delete it from tracking pool
                $origCallsPool->delete($actionId);
            }

            $evt = new Event('originate.response', $evtKeys);
            EventEmitter::getInstance()->emit($evt);
            return;
        }

        // try to get call in originated pool
        $uniqueid = $pbxEvtKeys['uniqueid'] ?? '';
        $originatedCall = $origCallsPool->getByUniqueid($uniqueid);
        if ($originatedCall) { // we found it
            $evtKeys['id'] = $originatedCall->getId();
            $evtKeys['destination'] = $originatedCall->getDestination()->getKeys();
            $evtKeys['bridge-target'] = $originatedCall->getBridgeTarget()->getKeys();
            $evtKeys['extra-data'] = $originatedCall->getExtraData();
        }

        if ($event == 'QueueCallerJoin') {
            $evtKeys['calleridnum'] = $pbxEvtKeys['calleridnum'];
            $evtKeys['uniqueid'] = $pbxEvtKeys['uniqueid'];
            $evtKeys['queue'] = $pbxEvtKeys['queue'];
            $evtKeys['count'] = $pbxEvtKeys['count'];
            $evtKeys['position'] = $pbxEvtKeys['position'];

            $evt = new Event('queue.join', $evtKeys);
            EventEmitter::getInstance()->emit($evt);
            return;
        }
        if ($event == 'QueueCallerLeave') {
            $evtKeys['calleridnum'] = $pbxEvtKeys['calleridnum'];
            $evtKeys['uniqueid'] = $pbxEvtKeys['uniqueid'];
            $evtKeys['queue'] = $pbxEvtKeys['queue'];
            $evtKeys['count'] = $pbxEvtKeys['count'];
            $evtKeys['position'] = $pbxEvtKeys['position'];
            if (isset($pbxEvtKeys['connectedlinenum'])) {
                $evtKeys['connectedlinenum'] = $pbxEvtKeys['connectedlinenum'];
            }
            $evt = new Event('queue.leave', $evtKeys);
            EventEmitter::getInstance()->emit($evt);
            return;
        }
        if ($event == 'QueueCallerAbandon') {
            $evtKeys['calleridnum'] = $pbxEvtKeys['calleridnum'];
            $evtKeys['uniqueid'] = $pbxEvtKeys['uniqueid'];
            $evtKeys['queue'] = $pbxEvtKeys['queue'];
            $evt = new Event('queue.abandon', $evtKeys);
            EventEmitter::getInstance()->emit($evt);
            return;
        }
        if ($event == 'AgentCalled') {
            $evtKeys['calleridnum'] = $pbxEvtKeys['calleridnum'];
            $evtKeys['uniqueid'] = $pbxEvtKeys['uniqueid'];
            $evtKeys['operator'] = $pbxEvtKeys['destcalleridnum'];
            $evtKeys['queue'] = $pbxEvtKeys['queue'];
            $evt = new Event('operator.call.init', $evtKeys);
            EventEmitter::getInstance()->emit($evt);
            return;
        }
        if ($event == 'AgentRingNoAnswer') {
            $evtKeys['calleridnum'] = $pbxEvtKeys['calleridnum'];
            $evtKeys['uniqueid'] = $pbxEvtKeys['uniqueid'];
            $evtKeys['operator'] = $pbxEvtKeys['destcalleridnum'];
            $evtKeys['queue'] = $pbxEvtKeys['queue'];
            $evtKeys['disposition'] = 'NO ANSWER';
            $evt = new Event('operator.call.response', $evtKeys);
            EventEmitter::getInstance()->emit($evt);
            return;
        }
        if ($event == 'AgentConnect') {
            $evtKeys['calleridnum'] = $pbxEvtKeys['calleridnum'];
            $evtKeys['uniqueid'] = $pbxEvtKeys['uniqueid'];
            $evtKeys['operator'] = $pbxEvtKeys['destcalleridnum'];
            $evtKeys['queue'] = $pbxEvtKeys['queue'];
            $evtKeys['disposition'] = 'ANSWERED';
            $evt = new Event('operator.call.response', $evtKeys);
            EventEmitter::getInstance()->emit($evt);
            return;
        }
        if ($event == 'AgentComplete') {
            $evtKeys['calleridnum'] = $pbxEvtKeys['calleridnum'];
            $evtKeys['uniqueid'] = $pbxEvtKeys['uniqueid'];
            $evtKeys['operator'] = $pbxEvtKeys['destcalleridnum'];
            $evtKeys['queue'] = $pbxEvtKeys['queue'];
            $evtKeys['talktime'] = $pbxEvtKeys['talktime'];
            $evtKeys['reason'] = $pbxEvtKeys['reason'];
            $evt = new Event('operator.call.complete', $evtKeys);
            EventEmitter::getInstance()->emit($evt);
            return;
        }
        if ($event == 'UserEvent') {
            $userEvtName = $pbxEvtKeys['userevent'];
            $evtKeys['uniqueid'] = $pbxEvtKeys['uniqueid'];
            switch ($userEvtName) {
                case 'a2i-connect-complete':
                    $copyFields = ['billsec', 'pbx-campaign', 'api-call-id', 'num', 'heard', 'callrec'];
                    foreach ($copyFields as $field) {
                        $evtKeys[$field] = $pbxEvtKeys[$field];
                    }
                    $evt = new Event('a2i.connect.complete', $evtKeys);
                    EventEmitter::getInstance()->emit($evt);
                    break;
                default: // unknown event
                    break;
            }
            return;
        }

/*        if (($event == 'Hold') || ($event == 'Unhold')) {
            $operator = $pbxEvtKeys['calleridnum'] ?? '';
            $callid = $pbxEvtKeys['uniqueid'] ?? '';
            //Logger::log("$operator $callid");

            if (!$operator || !$callid) {
                return;
            }

            $db = MyDB::getInstance();

            if ($event == 'Hold') {
                $db->query("insert into oper_hold_time(callid, operator) values('$callid', '$operator')");
            }

            if ($event == 'Unhold') {
                $db->query("update oper_hold_time set time_stop = now(),"
                ." duration = round(EXTRACT(EPOCH from now() - time_start)) where callid = '$callid'");
            }

            return;
        }
*/

        if ($event == 'Hangup') {
            if (!$originatedCall) {
                Logger::log("not ours hangup " . $pbxEvtKeys['uniqueid']);
                return;
            }
            Logger::log("hangup " . $pbxEvtKeys['uniqueid']);

            $evtKeys['calleridnum'] = $pbxEvtKeys['calleridnum'];
            $evtKeys['calleridname'] = $pbxEvtKeys['calleridname'];
            $evtKeys['uniqueid'] = $pbxEvtKeys['uniqueid'];
            $evtKeys['channel'] = $pbxEvtKeys['channel'];

            $evt =  new Event('originate.complete', $evtKeys);
            EventEmitter::getInstance()->emit($evt);

            // call was hung up and we delete it from tracking pool
            $origCallsPool->delete($originatedCall->getId());
            return;
        }
    }
}
