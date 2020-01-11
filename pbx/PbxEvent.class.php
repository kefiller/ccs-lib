<?php

namespace CCS\pbx;

/** Provides interface to Event, being received from PBX */
class PbxEvent
{
    /**
     * Server event was received from
     * @var string
     */
    private $srvName = 'unknown_srv';

    /**
     * Event's name
     * @var string
     */
    private $name = 'unknown';

    /**
     * Event's keys
     * @var string
     */
    private $keys = [];

    public function __construct(string $srvName, string $eventName, array $keys)
    {
        $this->srvName = $srvName;
        $this->name = $eventName;
        $this->keys = $keys;
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
        return $this->name;
    }

    /** Returns event's key/values pairs
     * @return string[]
     */
    public function getKeys()
    {
        return $this->keys;
    }
}
