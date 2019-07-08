<?php

namespace CCS;

use CCS\proto\IProtoHandler;

class EventEmitter
{
    private static $instance = null;

    /**
     * Array of protoHandlers, able to send events
     * @var array
     */
    private $protoHandlers = [];

    /**
     * Internal queue, holding received events before next processing
     * @var array
     */
    private $evtQueue = [];

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

    public function addProtoHandler(IProtoHandler $protoHandler)
    {
        $this->protoHandlers[] = $protoHandler;
    }

    /**
     * Emit's given event to subscribers
     * @return void
     */
    public function emit(Event $event)
    {
        $this->evtQueue[] = $event;
    }

    /**
     * Process event queue, actually sending them to subscribers
     * @return void
     */
    public function process()
    {
        $oSubscribers = Subscribers::getInstance();
        while (count($this->evtQueue)) {
            $event = array_shift($this->evtQueue);
            $aSubscribers = $oSubscribers->getSubscribers($event->getType());
            $data2Send = $event->serialize();
            foreach ($aSubscribers as $subscriberID) {
                foreach ($this->protoHandlers as $protoHandler) {
                    $protoHandler->send($data2Send, $subscriberID);
                }
            }
        }
    }
}
