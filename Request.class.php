<?php

namespace CCS;

use CCS\serialize\JsonSerializer;

class Request implements \ArrayAccess
{
    /** @var string */
    private $sRequest = '';
    /** @var array */
    private $oRequest = null;
    /** @var Result */
    private $oResult = null;
    /** @var callable */
    private $fHandleFunc = null;
    /** @var object */
    private $oProtoHandler = null;
    /**
     * ID of connected client. For now used for storing websocket $fd,
     * to implement event subscription.
     */
    private $clientID;

    /** @param object $protoHandler */
    public function __construct(string $sRequest, $protoHandler)
    {
        $this->sRequest = $sRequest;
        // @phan-suppress-next-line PhanTypeMismatchProperty
        $this->oProtoHandler = $protoHandler;
        $this->oResult = $this->checkRequest();
    }

    public function setClientID($clientID)
    {
        $this->clientID = $clientID;
    }

    public function getClientID()
    {
        return $this->clientID;
    }

    /** @implements ArrayAccess */
    public function offsetSet($offset, $value)
    {
        if (is_null($offset)) {
            $this->oRequest[] = $value;
        } else {
            $this->oRequest[$offset] = $value;
        }
    }

    public function offsetExists($offset)
    {
        return isset($this->oRequest[$offset]);
    }

    public function offsetUnset($offset)
    {
        unset($this->oRequest[$offset]);
    }

    public function offsetGet($offset)
    {
        return isset($this->oRequest[$offset]) ? $this->oRequest[$offset] : null;
    }

    public function protoHandler()
    {
        return $this->oProtoHandler;
    }

    public function result()
    {
        return $this->oResult;
    }

    public function handle()
    {
        $handleFunc = $this->fHandleFunc;
        return $handleFunc($this);
    }

    private function checkRequest()
    {
        global $methods, $authTokens;

        $serializer = new JsonSerializer();

        $this->oRequest = $serializer->unserialize($this->sRequest);

        // Basic checks
        if ($this->oRequest == null) {
            return new ResultError("Could not decode request", 2);
        }

        if (!isset($this->oRequest['method'])) {
            return new ResultError("Missing 'method' field in request", 3);
        }

        $method = $this->oRequest['method'];
        if (!isset($methods[$method])) {
            return new ResultError("No such method '$method'", 4);
        }

        $result = $this->checkRequestAuth();
        if (!$result) {
            return new ResultError("Bad auth", 5);
        }

        $result = $this->validateRequestParams();
        if ($result === null) {
            return new ResultError("Error checking method's params. Something wrong with data?", 6);
        }

        if ($result === false) {
            // Something bad with request params
            return new ResultError("Bad request params. See method params description", 7);
        }

        $handleFunc = str_replace('.', '_', $method); // 'system.ping' => system_ping
        $handleFunc = "local\\$handleFunc";
        if (!is_callable($handleFunc)) {
            return new ResultError("No handler function exists for method '$method'", 8);
        }
        $this->fHandleFunc = $handleFunc;
        return new ResultOK();
    }

    public function checkRequestAuth()
    {
        global $methods, $authTokens;

        $method = $this->oRequest['method'];
        if (!isset($methods[$method]['auth'])) {
            return null;
        }
        $methodAuth = $methods[$method]['auth'];
        if (!in_array($methodAuth, ['yes','no'])) {
            return null;
        }
        if ($methodAuth == 'no') {
            return true;
        }

        // otherwise auth is yes
        if (!isset($this->oRequest['auth'])) {
            return null;
        }
        $reqAuth = $this->oRequest['auth']; // request auth token
        if (!in_array($reqAuth, $authTokens)) {
            return false; // no such token
        }
        return true;
    }

    public function validateRequestParams()
    {
        global $methods, $authTokens;

        $method = $this->oRequest['method'];

        if (!isset($methods[$method]['params'])) {
            return null;
        }

        $declParams = $methods[$method]['params'];
        if (!is_array($declParams)) {
            return null;
        }
        if (count($declParams) == 0) { // empty array, no params required
            return true;
        }

        // Some params are defined
        if (!isset($this->oRequest['params'])) {
            return false;
        } // but nothing in request

        $reqParams = $this->oRequest['params'];

        foreach ($declParams as $paramName => $paramDecl) {
            if (!isset($paramDecl['required']) || !isset($paramDecl['type'])) {
                return null;
            }
            $paramRequired = $paramDecl['required'];
            $paramType     = $paramDecl['type'];
            if ($paramRequired && !isset($reqParams[$paramName])) {
                return false;
            } // required param not set
            $typeFunc = "is_{$paramType}";
            if (isset($reqParams[$paramName]) && !$typeFunc($reqParams[$paramName])) {
                return false;
            } // required param of wrong type
        }

        return true;
    }
}
