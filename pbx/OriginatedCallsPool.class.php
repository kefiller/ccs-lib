<?php

namespace CCS\pbx;

/** Singleton class, holds array of originated calls */
class OriginatedCallsPool
{
    /** @var array */
    private $pool;

    /**
     * @var array
     *
     * Map uniqueid -> guid call id
     */
    private $idMap;

    private static $instance = null;

    private function __clone()
    {
    }

    private function __construct()
    {
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function add(OriginatedCall $call)
    {
        $callid = $call->getId();
        $this->pool[$callid] = $call;

        $uniqueId = $call->getUniqueid();
        if ($uniqueId) {
            $this->idMap[$uniqueId] = $callid;
        }
    }

    public function delete(string $callid)
    {
        $origCall = $this->pool[$callid] ?? null;
        if (!$origCall) {
            return;
        }
        $uniqueId = $origCall->getUniqueid();
        if ($uniqueId) {
            unset($this->idMap[$uniqueId]);
        }
        unset($this->pool[$callid]);
    }

    /** @return OriginatedCall|null */
    public function get(string $id)
    {
        if (isset($this->pool[$id])) {
            return $this->pool[$id];
        }
        return null;
    }

    /** @return OriginatedCall|null */
    public function getByUniqueid(string $uniqueid)
    {
        $id = $this->idMap[$uniqueid] ?? null;
        if (!$id) {
            return null;
        }
        return $this->get($id);
    }

    /** @return array */
    public function getAll()
    {
        return $this->pool;
    }

    /** @return int */
    public function getCount()
    {
        return \count($this->pool);
    }

    public function pop(string $id)
    {
        $call = $this->get($id);
        if (!$call) {
            return null;
        }
        $this->delete($id);
        return $call;
    }
}
