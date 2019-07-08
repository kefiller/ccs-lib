<?php

namespace CCS;

class ResultOK extends Result
{
    public function __construct($data = null)
    {
        $this->errorCode = 0;
        $this->data = $data;
    }
}
