<?php

namespace CCS;

class ResultError extends Result
{
    public function __construct(string $errorMsg, int $errorCode = -1)
    {
        $this->errorCode = $errorCode;
        $this->data = $errorMsg;
    }
}
