<?php

namespace CCS;

class MsgResponse
{
    private $result;

    public function __construct(Result $result)
    {
        $this->result = $result;
    }

    public function data()
    {
        if ($this->result->ok()) {
            return ['result' => $this->result->data()];
        }
        return ['error' =>  [
            'code' => $this->result->errorCode(),
            'message' => $this->result->errorDesc()
            ]
        ];
    }
}
