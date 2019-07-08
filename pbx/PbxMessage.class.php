<?php

namespace CCS\pbx;

/** Provides interface to message, being sent to PBX */
class PbxMessage
{
    /** @var string */
    private $messageType;
    /** @var array */
    private $data;
    /** @var \PAMI\Message\OutgoingMessage */
    private $pamiMessage;

    public function __construct(string $messageType, array $data)
    {
        $this->messageType = $messageType;
        $this->data = $data;
    }

    /** @return \PAMI\Message\OutgoingMessage */
    public function asPAMI()
    {
        return $this->pamiMessage;
    }
}
