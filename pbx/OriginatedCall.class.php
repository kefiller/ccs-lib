<?php

namespace CCS\pbx;

use CCS\util\_;
use \DateTime;

class OriginatedCall
{
    /** @var string */
    private $id;

    /**
     * @var string
     *
     * PBX's id of call
     * */
    private $uniqueid;

    /** @var DateTime */
    private $statusUpdateTime;

    /** @var string */
    private $status;

    /** @var OriginateTarget */
    private $destination;

    /** @var OriginateTarget */
    private $bridgeTarget;

    /**
     * @var array
     *
     * Some extra data that call may have
    */
    private $extraData;

    public function __construct(OriginateTarget $destination, OriginateTarget $bridgeTarget, array $extraData = [], $callId = '')
    {
        if (!$callId) {
            $this->id = _::guidv4();
        } else {
            $this->id = $callId;
        }
        $this->statusUpdateTime = new DateTime();
        $this->status = 'created';
        $this->destination = $destination;
        $this->bridgeTarget = $bridgeTarget;
        $this->extraData = $extraData;
    }

    /** @return string */
    public function getId()
    {
        return $this->id;
    }

    /** @return string */
    public function getStatus()
    {
        return $this->status;
    }

    public function setStatus(string $status)
    {
        $this->status = $status;
        $this->statusUpdateTime = new DateTime();
    }

    /** @return OriginateTarget */
    public function getDestination()
    {
        return $this->destination;
    }

    /** @return OriginateTarget */
    public function getBridgeTarget()
    {
        return $this->bridgeTarget;
    }

    /** @return DateTime */
    public function getStatusUpdateTime()
    {
        return $this->statusUpdateTime;
    }

    /** @return array */
    public function getExtraData()
    {
        return $this->extraData;
    }

    /** @return void */
    public function setUniqueid(string $uniqueid)
    {
        $this->uniqueid = $uniqueid;
    }

    /** @return string */
    public function getUniqueid()
    {
        return $this->uniqueid;
    }
}
