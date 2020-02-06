<?php

namespace CCS\transport;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

use CCS\ResultError;
use CCS\ResultOK;
use CCS\Result;
use CCS\Logger;

/** App message bus(singleton). Used for transparent communication
 * of application parts, local or distributed.
 * */

class MessageBus
{
    /** @var array */
    private $config;

    /** @var callable|null */
    private $evtListener = null;

    /** @var MessageBus */
    private static $instance = null;

    /** @var AMQPStreamConnection */
    private $rmqConnection = null;

    /** @var any */
    private $rmqChannel = null;

    /** @var any */
    private $rmqEvtQueue = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** save config and init bus connection*/
    public function init(array $config)
    {
        $this->config = $config;
        $host = $config['host'];
        $port = $config['port'];
        $user = $config['user'];
        $pass = $config['pass'];
        $event_exchange = $config['event_exchange'];
        $event_routing_key_prefix = $config['event_routing_key_prefix'];
        $cmd_exchange = $config['cmd_exchange'];
        $cmd_routing_key_prefix = $config['cmd_routing_key_prefix'];

        $this->rmqConnection = new AMQPStreamConnection($host, $port, $user, $pass);
        $this->rmqChannel    = $this->rmqConnection->channel();

        // declare (and create if not exists) exchange with given name for events
        $this->rmqChannel->exchange_declare($event_exchange, 'topic', false, false, false);
        // same for command exhcange
        $this->rmqChannel->exchange_declare($cmd_exchange, 'topic', false, false, false);

        // let RabbitMQ generate name and declare a temporary queue (living while this process lives)
        // for receiving events
        list($this->rmqEvtQueue,,) = $this->rmqChannel->queue_declare("", false, false, true, false);

        // Bind queue to exchange and receive events with routing key, beginning with $event_routing_key_prefix
        $this->rmqChannel->queue_bind($this->rmqEvtQueue, $event_exchange, "$event_routing_key_prefix.#");

        $this->rmqChannel->basic_consume($this->rmqEvtQueue, '', false, true, false, false, function ($msg) {
            $payload = json_decode($msg->body, true);
            if (!$payload) return;
            $lowerCaseKeysPayload = [];
            foreach($payload as $k => $v) {
                $lowerCaseKeysPayload[strtolower($k)] = $v;
            }
            if($this->evtListener) {
                ($this->evtListener)($lowerCaseKeysPayload);
            }
        });
    }

    /* Periodically call to receive messages from queue and process them */
    public function process() {
        if ($this->rmqChannel->is_consuming()) {
            $this->rmqChannel->wait(/*$allowed_methods*/ null, /*$non_blocking*/ true);
        }
    }

    /* Emit message. If empty $dst, send broadcast */
    public function send(array $message, string $dst = null) {
        $data = json_encode($message);
        $routingKey = $this->config['cmd_routing_key_prefix'] . ($dst ? ".$dst" : '');
        $this->rmqChannel->basic_publish(
            new AMQPMessage($data),
            $this->config['cmd_exchange'],
            $routingKey
        );
    }

    /* Gracefully close connection */
    public function close() {
        $this->rmqChannel->close();
        $this->rmqConnection->close();
    }

    /** Subscribe to events from PBX pool */
    public function subscribe(callable $evtListener)
    {
        $this->evtListener = $evtListener;
    }

    private function __clone()
    {
    }

    private function __construct()
    {
    }
}
