<?php

namespace CCS;

class Subscribers
{
    private $eventsSubscription  = [];
    private static $instance = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    private function __clone()
    {
    }
    private function __construct()
    {
    }

    public function add($subscriberId, array $events)
    {
        global $CCS_API_events;
        // only specified events or 'ALL' (all available events)
        if (in_array('ALL', $events)) {
            $events = array_keys($CCS_API_events);
        }
        foreach ($events as $event) {
            $evtSubscribers = $this->getSubscribers($event);
            if (!in_array($subscriberId, $evtSubscribers)) {
                $evtSubscribers[] = $subscriberId;
            }
            $this->eventsSubscription[$event] = $evtSubscribers;
        }
    }

    public function delete($subscriberId, array $events)
    {
        global $CCS_API_events;
        // only specified events or 'ALL' (all available events)
        if (in_array('ALL', $events)) {
            $events = array_keys($CCS_API_events);
        }

        foreach ($events as $event) {
            if (!isset($this->eventsSubscription[$event])) {
                continue;
            }
            $evtSubscribers = $this->eventsSubscription[$event];
            // delete $subscriberId from $evtSubscribers
            $newEvtSubscribers = array_diff($evtSubscribers, [$subscriberId]);
            if (!count($newEvtSubscribers)) {
                unset($this->eventsSubscription[$event]);
            } else {
                $this->eventsSubscription[$event] = $newEvtSubscribers;
            }
        }
    }

    public function getSubscribers(string $evtType)
    {
        $evtSubscribers = [];
        if (isset($this->eventsSubscription[$evtType])) {
            $evtSubscribers = $this->eventsSubscription[$evtType];
        }
        return $evtSubscribers;
    }
}
