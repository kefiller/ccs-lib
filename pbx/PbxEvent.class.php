<?php

namespace CCS\pbx;

/** Provides interface to Event, being received from PBX */
class PbxEvent
{
    /** @var \PAMI\Message\Event\EventMessage */
    private $pamiEvent;

    /**
     * Server event was received from
     * @var string
     */
    private $srvName = '';

    public function __construct(\PAMI\Message\Event\EventMessage $pamiEvent, string $srvName)
    {
        $this->pamiEvent = $pamiEvent;
        $this->srvName = $srvName;
    }

    /**
     * Returns name of server event was received from
     * @return string
     */
    public function getSrvName()
    {
        return $this->srvName;
    }

    /** Returns event's name
     * @return string
     */
    public function getName()
    {
        return $this->pamiEvent->getName();
    }

    /** Returns event's key/values pairs
     * @return string[]
     */
    public function getKeys()
    {
        return $this->pamiEvent->getKeys();
    }
}
