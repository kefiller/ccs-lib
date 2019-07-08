<?php

namespace CCS;

class Result
{
    protected $data = null;
    protected $errorCode = 0;

    public function ok()
    {
        return $this->errorCode === 0;
    }

    public function error()
    {
        return $this->errorCode !== 0;
    }

    public function errorDesc()
    {
        return $this->data;
    }

    public function errorCode()
    {
        return $this->errorCode;
    }

    public function data()
    {
        return $this->data;
    }
}
