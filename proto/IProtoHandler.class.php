<?php

namespace CCS\proto;

use CCS\serialize\JsonSerializer;
use CCS\serialize\ISerializer;
use CCS\serialize\XMLSerializer;
use CCS\MsgResponse;
use CCS\ResultError;
use CCS\ResultOK;
use CCS\Result;
use CCS\Request;
use CCS\Logger;

/**
 * Provides basic functions to working with protocols(http/websockets)
 */
abstract class IProtoHandler
{
    /** @var ISerializer */
    protected $jsonSerializer;
    /** @var ISerializer */
    protected $xmlSerializer;
    protected $defaultFormat = 'json';

    /**
     * Log or not incoming requests and  request responses
     */
    protected $logRequest = true;
    protected $logResponse = false;

    protected function __construct()
    {
        $this->jsonSerializer = new JsonSerializer();
        $this->xmlSerializer = new XMLSerializer();
    }

    protected function serializeResult(Result $rslt)
    {
        // wrapper object
        $msgResponse = new MsgResponse($rslt);
        $rsltData = $rslt->data();

        $format = $this->defaultFormat;
        if (isset($rsltData['format'])) {
            $format = $rsltData['format'];
        }

        $serializer = null;
        switch ($format) {
            case 'json':
                $serializer = $this->jsonSerializer;
                break;
            case 'xml':
                $serializer = $this->xmlSerializer;
                break;
        }

        if (!$serializer) {
            $errMsg = 'No serializer for format ' . $format;
            Logger::log($errMsg);
            return new ResultError($errMsg);
        }

        // text to return to client
        $ansText = $serializer->serialize($msgResponse->data());
        return new ResultOK($ansText);
    }

    /**
     *  Set Log options: log or not requests and responses
     * */
    public function setLogOpts(bool $logRequest, bool $logResponse)
    {
        $this->logRequest  = $logRequest;
        $this->logResponse = $logResponse;
    }

    abstract public function proto();
    abstract public function response(Result $rslt, Request $req = null);
    abstract public function send(string $rawData, $clientID);
}
