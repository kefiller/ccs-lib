<?php

namespace CCS\pbx;

use CCS\ResultError;
use CCS\ResultOK;
use CCS\Result;
use CCS\Logger;

use CCS\db\MyDB;

/** Pool of PBX clients(singleton). Used as center point for receiving events from group of PBX servers,
 * and sending commands to whole PBX group.
 * */

class PbxPool
{
    /** @var array */
    private $connectOptions;

    /**
     * Array of PbxClient
     * @var array */
    private $pool;

    /** @var callable|null */
    private $evtListener = null;

    /** @var PbxPool */
    private static $instance = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Set connection params, init PBX pool*/
    public function init(array $connectOptions, bool $autoConnect = true)
    {
        $this->connectOptions = $connectOptions;
        foreach ($this->connectOptions as $srvName => $connData) {
            $pbxClient = new PbxClient($srvName, $connData, false);
            $this->pool[$srvName] = [
                'client' => $pbxClient,
                'connected' => false,
            ];
        }
        if ($autoConnect) {
            $this->connectPool();
        }
    }

    /** Connect to servers from pool
     *
     * @return void
    */
    public function connectPool(bool $force = false)
    {
        foreach ($this->pool as $srvName => $srvData) {
            if ($srvData['connected'] && !$force) {
                continue;
            }
            $this->tryConnectClient($srvName);
        }
    }

    /** Process incoming messages and events from PBX pool */
    public function processPool()
    {
        foreach ($this->pool as $srvName => $srvData) {
            if (!$srvData['connected']) {
                continue;
            }
            $ret = $this->processClient($srvName);
            if ($ret->error()) {
                Logger::log($srvName . " - processing error: " . $ret->data());
                $this->scheduleReconnect($srvName);
            }
        }
    }

    /** Subscribe to events from PBX pool */
    public function subscribe(callable $evtListener)
    {
        $this->evtListener = $evtListener;
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
        foreach ($this->pool as $srvName => $srvData) {
            if (!$srvData['connected']) {
                continue;
            }
            $pbxClient = $srvData['client'];
            assert($pbxClient instanceof PbxClient);

            if (empty($targetPbxs) || in_array($srvName, $targetPbxs)) {
                $result = $pbxClient->originate($oDst, $oBrt, $extraData);
                $resultRecord = ['srv' => $srvName];
                if ($result->error()) {
                    //$poolResult[$srvName]  = ['error' => $result->errorDesc()];
                    $resultRecord['result'] = 'error';
                    $resultRecord['error']  =  $result->errorDesc();
                } else {
                    //$poolResult[$srvName] = ['success' => $result->data()];
                    $resultRecord['result']  = 'success';
                    $resultRecord['call-id'] = $result->data()['id'];
                }
                $poolResult[$srvName] = $resultRecord;
            }
        }

        if (empty($poolResult)) {
            return new ResultError("No servers found to run command on.");
        }

        return new ResultOK($poolResult);
    }

    /**
     * Try to connect client with given $srvName
     *
     * @return Result
     */
    private function connectClient(string $srvName)
    {
        $pbxClient = $this->pool[$srvName]['client'];
        if ($this->evtListener) {
            $pbxClient->subscribe($this->evtListener);
        }
        try {
            $pbxClient->connect();
        } catch (\Exception $e) {
            $this->pool[$srvName]['connected'] = false;
            return new ResultError($e->getMessage());
        }
        $this->pool[$srvName]['connected'] = true;
        return new ResultOK();
    }

    private function tryConnectClient(string $srvName)
    {
        Logger::log($srvName . " - connecting ...");
        $ret = $this->connectClient($srvName);
        if ($ret->ok()) {
            Logger::log($srvName . " - connection successfull");
        } else {
            Logger::log($srvName . " - connection error: " . $ret->data());
            $this->scheduleReconnect($srvName);
        }
    }

    /**
     * Process incoming messages and events from PBX client with given $srvName
     *
     * @return Result
    */
    private function processClient($srvName)
    {
        $pbxClient = $this->pool[$srvName]['client'];
        try {
            $pbxClient->process();
        } catch (\Exception $e) {
            $this->pool[$srvName]['connected'] = false;
            return new ResultError($e->getMessage());
        }

        return new ResultOK();
    }

    /**
     * Schedule reconnection to given $srvName in few seconds in future
     * Reconnection time is randomized in time range from 5 to 15 seconds
     */
    private function scheduleReconnect(string $srvName)
    {
        $reconnectSeconds = rand(5, 15);
        Logger::log($srvName . " - scheduling reconnection in $reconnectSeconds seconds");
        // @phan-suppress-next-line PhanUnusedClosureParameter,PhanUndeclaredClassMethod
        swoole_timer_after($reconnectSeconds*1000, function () use ($srvName) {
            $this->tryConnectClient($srvName);
        });
    }

    private function __clone()
    {
    }

    private function __construct()
    {
    }
}
