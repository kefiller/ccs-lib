<?php

namespace CCS\proto;

use CCS\Request;
use CCS\Result;
use CCS\Logger;

class WebsocketHandler extends IProtoHandler
{
    private static $instance = null;
    private $wsServer = null;

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
    protected function __construct()
    {
        parent::__construct();
    }

    // @phan-suppress-next-line PhanUndeclaredTypeParameter
    public function setWSServer(\swoole_websocket_server $wsServer)
    {
        $this->wsServer = $wsServer;
    }

    public function process($frame)
    {
        Logger::log("receive from {$frame->fd}:{$frame->data},opcode:{$frame->opcode},fin:{$frame->finish}");
        //$this->wsServer->push($frame->fd, "pong: {$frame->data}");

        $oRequest = new Request($frame->data, $this);
        $oRequest->setClientID($frame->fd);
        $oReqRslt = $oRequest->result();
        if ($oReqRslt->error()) { // if bad request
            $this->response($oReqRslt, $oRequest); // send error to client
            return $oReqRslt;
        }
        return $this->response($oRequest->handle(), $oRequest); // send result to client
    }

    public function proto()
    {
        return 'websocket';
    }

    public function response(Result $rslt, Request $req = null)
    {
        $result = $this->serializeResult($rslt);
        if ($result->error()) {
            return $result;
        }

        // text to return to client
        $ansText = $result->data();

        // TODO log request/answer
        //$this->logReq($ansText);

        // send answer to client
        $clientID = $req->getClientID();
        return $this->send($ansText, $clientID);
    }

    public function send(string $rawData, $clientID)
    {
        // @phan-suppress-next-line PhanUndeclaredClassMethod
        return $this->wsServer->push($clientID, $rawData);
    }
}
