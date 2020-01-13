<?php

namespace CCS\pbx;

use CCS\ResultError;
use CCS\ResultOK;
use CCS\Result;
use CCS\Logger;

use CCS\db\MyDB;

use CCS\transport\MessageBus;
use CCS\util\_;

use CCS\Event;
use CCS\pbx\PbxEvent;
use CCS\EventEmitter;
use CCS\EventResponder;

/** Pool of PBX clients(singleton). Used as center point for receiving events from group of PBX servers,
 * and sending commands to whole PBX group.
 * */

class PbxPool
{
    /** @var array */
    private $servers;

    /** @var PbxPool */
    private static $instance = null;

    /** Context, where to put originated calls
    * @var string
    */
    private $originateContext = '';

    /** Absolute call timeout (remove it from originatedCallsPool without conditions) */
    private $callAbsTimeout = 1800; // seconds

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Configure PBX pool*/
    public function init(array $config)
    {
        $this->servers = $config['servers'];
        $this->originateContext = $config['originate-context'];

        // Subscribe for events from MessageBus
        MessageBus::getInstance()->subscribe(function($evt)  {
            $pbxEvent = new PbxEvent($evt['event'], $evt['srv'], $evt);
            EventResponder::getInstance()->respond($pbxEvent);
            //Logger::log($evt['srv'] . "->" . $evt['event']);
        });
    }

    /**
     * Originates (on whole pool) call to given $destination, on answer connecting to $bridgeTarget
     * If trunk is found on specified client(s), outgoing call is created only there.
     *
     * @return Result
     */
    public function originate(array $destination, array $bridgeTarget, array $extraData = [])
    {
        $dstRslt = OriginateTarget::create($destination);
        if ($dstRslt->error()) {
            return new ResultError("Destination: " . $dstRslt->errorDesc());
        }

        $brtRslt = OriginateTarget::create($bridgeTarget);
        if ($brtRslt->error()) {
            return new ResultError("Bridge-target: " . $brtRslt->errorDesc());
        }

        $oDst = $dstRslt->data();
        $oBrt = $brtRslt->data();

        $pbxSrv = $oDst->getPbxSrv();
        $trunk = $oDst->getTrunk();

        // PBXs, where to run command on
        $targetPbxs = [];
        if ($pbxSrv) {
            $targetPbxs[] = $pbxSrv;
        }

        // If trunk specified and pbx-srv not set, find where its located
        if ($trunk && empty($targetPbxs)) {
            $db = MyDB::getInstance();

            $result = $db->query("select location from trunk_location where trunk = '$trunk'");
            if (!count($result)) {
                return new ResultError("Could not find trunk location fo trunk $trunk");
            }
            foreach ($result as $row) {
                $location = $row['location'];
                $targetPbxs[] = $location; // add trunk to target pbx array
            }
        }

        $poolResult = [];
        foreach ($this->servers as $srvName) {
            if (empty($targetPbxs) || in_array($srvName, $targetPbxs)) {
                $originateAction = $this->buildOriginateAction($oDst, $oBrt, $extraData);
                $callId = $originateAction['keys']['actionid'];
                $this->command($originateAction, $srvName);
                $this->storeCallInOriginatedPool($oDst, $oBrt, $extraData, $callId);
                $this->emitOriginateInitEvent($oDst->getKeys(), $oBrt->getKeys(), $extraData, $callId);

                $poolResult[$srvName] = [
                    'srv' => $srvName,
                    'result' => 'success',
                    'call-id' => $callId,
                ];
            }
        }

        if (empty($poolResult)) {
            return new ResultError("No servers found to run command on.");
        }

        return new ResultOK($poolResult);
    }

    /**
     * Build 'originate' outgoing call action to $destination, connecting on answer to $bridgeTarget
     * @return Result
     * */
    private function buildOriginateAction(OriginateTarget $destination, OriginateTarget $bridgeTarget, array $extraData = [])
    {
        $channel = $destination->getChannel();

        $keys = [];
        $vars = [];

        $keys['action'] = 'Originate';
        $keys['channel'] = $channel;
        $keys['callerid'] = $destination->getCallerid();
        $keys['timeout'] = $destination->getTimeout()*1000; // To milliseconds

        // Set pbx- variables from extraData
        foreach ($extraData as $k => $v) {
            if (substr(strtolower($k), 0, 4) == 'pbx-') {
                $vars[$k] = $v;
            }
        }

        $keys['context'] = $this->originateContext;
        // EXTEN will be set later
        $keys['priority'] = '1';

        $vars['BRIDGE-TARGET'] = $bridgeTarget->getType();

        if ($bridgeTarget->getType() == 'number') {
            $vars['BRT-NUM'] = $bridgeTarget->getNumber();
            $keys['exten'] = $bridgeTarget->getNumber(); // set EXTEN to number
            if ($bridgeTarget->getCallerid()) {
                $vars['BRT-CLID'] = $bridgeTarget->getCallerid();
            }
        } elseif ($bridgeTarget->getType() == 'dialplan') {
            $vars['BRT-CTX'] = $bridgeTarget->getContext();
            $vars['BRT-EXTEN'] = $bridgeTarget->getExtension(); // set EXTEN to exten
            $keys['exten'] = $bridgeTarget->getExtension();
        }

        $keys['async'] = 'true';

        $callId = _::guidv4();

        $keys['actionid'] = $callId;
        $vars['API-CALL-ID'] = $callId;

        return [
            'action_type' => 'originate',
            'keys' => $keys,
            'vars' => $vars
        ];
    }

    /* Stores call in pool of originated calls for later monitoring */
    private function storeCallInOriginatedPool(OriginateTarget $destination, OriginateTarget $bridgeTarget, array $extraData, string $callId) {
        // Create OriginatedCall
        $origCall = new OriginatedCall($destination, $bridgeTarget, $extraData, $callId);
        $origCall->setStatus("originated");
        $originatedCallsPool = OriginatedCallsPool::getInstance();
        $originatedCallsPool->add($origCall);
        // Force remove call from OriginatedPool after call timeout*1.2 (in case we loose OriginateResponse or whatever)
        \swoole_timer_after($destination->getTimeout()*1.2*1000 /*to milliseconds*/, function() use ($originatedCallsPool, $callId) {
            $origCall = $originatedCallsPool->get($callId);
            if(!$origCall) {
                //Logger::log("OriginatedCallsPool tracker - no call with id: '$callId' in pool, looks ok(already removed)");
                return;
            }
            $uniqueId = $origCall->getUniqueid();
            if($uniqueId) {
                Logger::log("OriginatedCallsPool tracker - call with id: '$callId' found in pool with uniqueId:'$uniqueId', looks still talking, leave it");
                // track and remove call after finally completed (in case lost Hangup event or whatever)
                \swoole_timer_after($this->callAbsTimeout, function() use($originatedCallsPool, $callId) {
                    Logger::log("OriginatedCallsPool tracker - final cleanup call id: '$callId'");
                    $originatedCallsPool->delete($callId);
                });
                return;
            }
            Logger::log("OriginatedCallsPool tracker - call with id: '$callId' found in pool with no uniqueId, looks BAD, remove it");
            $originatedCallsPool->delete($callId);
            return;
        });
    }

    /*
    * Send $action command to PBX. If $dst is empty, broadcast to all available PBX's
    */
    private function command($action, $dst = '') {
        MessageBus::getInstance()->send($action, $dst);
    }

    /* Creates and emits 'originate.init' events to subscribed clients */
    private function  emitOriginateInitEvent(array $dstKeys, array $bridgeTargetKeys, array $extraData, string $callId) {
        $evtKeys = [];
        $evtKeys['destination'] = $dstKeys;
        $evtKeys['bridge-target'] = $bridgeTargetKeys;
        $evtKeys['extra-data'] = $extraData;
        $evtKeys['id'] = $callId;

        $evt = new Event('originate.init', $evtKeys);
        EventEmitter::getInstance()->emit($evt);
    }

    private function __clone()
    {
    }

    private function __construct()
    {
    }
}
