<?php

namespace CCS\proto;

use CCS\ResultError;
use CCS\Request;
use CCS\Logger;
use CCS\Result;

class HTTPHandler extends IProtoHandler
{
    private $httpRequest  = null;
    private $httpResponse = null;

    public function __construct($httpRequest, $httpResponse)
    {
        parent::__construct();
        $this->httpRequest  = $httpRequest;
        $this->httpResponse = $httpResponse;
    }

    public function process()
    {
        $reqUri = $this->httpRequest->server['request_uri'];
        $refReqUri = "/api/v1/";

        if ($reqUri != $refReqUri) {
            $rslt = new ResultError("Wrong request_uri in request($reqUri), should be $refReqUri", 11);
            $this->response($rslt);
            return $rslt;
        }

        if (!isset($this->httpRequest->post['request'])) {
            $rslt = new ResultError("No 'request' field in POST data", 1);
            $this->response($rslt);
            return $rslt;
        }

        // request (usually json text) is in 'request' field
        $oRequest = new Request($this->httpRequest->post['request'], $this);
        $oReqRslt = $oRequest->result();

        if ($oReqRslt->error()) { // if bad request
            $this->response($oReqRslt); // send error to client
            return $oReqRslt;
        }

        return $this->response($oRequest->handle()); // send result to client
    }

    public function proto()
    {
        return 'http';
    }

    // @phan-suppress-next-line PhanUnusedPublicMethodParameter
    public function response(Result $rslt, Request $req = null)
    {
        $result = $this->serializeResult($rslt);
        if ($result->error()) {
            return $result;
        }
        // text to return to client
        $ansText = $result->data();
        // log request/answer
        $this->logReq($ansText);

        $this->httpResponse->header('Access-Control-Allow-Origin', '*');

        // finish http request
        return $this->httpResponse->end($ansText);
    }

    private function logReq($ans)
    {
        $host = $this->httpRequest->header['host'];
        $reqUri = $this->httpRequest->server['request_uri'];
        $method = $this->httpRequest->server['request_method'];
        $remoteAddr = $this->httpRequest->server['remote_addr'];
        $remotePort = $this->httpRequest->server['remote_port'];
        if (isset($this->httpRequest->post['request'])) {
            $req = $this->httpRequest->post['request'];
        } else {
            $req = "";
        }
        $trimReq = substr(str_replace(["\r","\n"], "", $req), 0, 255);
        if ($this->logRequest) {
            $logStr = "REQUEST [$remoteAddr:$remotePort $method -> $host$reqUri $trimReq]";
            if ($this->logResponse) {
                $trimAns = str_replace(["\r","\n"], "", $ans);
                $logStr .= " RESPONSE [$trimAns]";
            }
            Logger::log($logStr);
        }
    }

    // @phan-suppress-next-line PhanUnusedPublicMethodParameter
    public function send(string $rawData, $clientID)
    {
        return false; //not implemented
    }
}
