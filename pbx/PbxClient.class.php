<?php

namespace CCS\pbx;

use CCS\ResultOK;
use CCS\Result;
use CCS\Event;
use CCS\EventEmitter;

/** Class maintaining connection to PBX, sending commands and receiving events */
class PbxClient
{
    /**
     * PbxClient name
     *
     * @var string
     * */
    private $name;
    /** @var array */
    private $connectOptions;
    /** @var \PAMI\Client\Impl\ClientImpl */
    private $pamiClient;
    /** @var callable|null */
    private $evtListener = null;
    /** @var string */
    private $evtListenerId = '';

    /** @var string */
    private $originateContext = 'api-originate';

    /** Creates new PBX client and optionally establishes connection */
    public function __construct(
        string $name,
        array $connectOptions,
        bool $autoConnect = true
    ) {
        $this->name = $name;
        $this->connectOptions = $connectOptions;
        $this->pamiClient = new \PAMI\Client\Impl\ClientImpl($this->connectOptions);
        if ($autoConnect) {
            $this->connect();
        }
    }

    /** Returns name of PBX client
     * @return string
     */
    public function name()
    {
        return $this->name;
    }

    /** Subscribe to events from PBX */
    public function subscribe(callable $evtListener)
    {
        $this->evtListener = $evtListener;
        $this->evtListenerId = $this->pamiClient->registerEventListener(
            function (\PAMI\Message\Event\EventMessage $event) {
                $func = $this->evtListener;
                $func(new PbxEvent($this->name, $event->getName(), $event->getKeys()));
            }
        );
    }

    /** Unsubscribe from PBX events */
    public function unsubscribe()
    {
        $this->evtListener = null;
        $this->pamiClient->unregisterEventListener($this->evtListenerId);
        $this->evtListenerId = '';
    }

    /** Send message to PBX */
    public function send(PbxMessage $message)
    {
        $this->pamiClient->send($message->asPAMI());
    }

    /** Tries to establish connection to PBX
     * @throws \PAMI\Client\Exception\ClientException
    */
    public function connect()
    {
        $this->pamiClient->open();
        // Filter out some unused events on Asterisk side
        $this->pamiClient->send(new FilterAction('!RTCP*'));
        $this->pamiClient->send(new FilterAction('!VarSet'));
        $this->pamiClient->send(new FilterAction('!Newexten'));
        $this->pamiClient->send(new FilterAction('!LocalBridge'));
    }

    /** Closes connection to PBX */
    public function disconnect()
    {
        $this->pamiClient->close();
    }

    /** Process incoming messages and events from PBX (have to be periodic called from) */
    public function process()
    {
        $this->pamiClient->process();
    }

    /**
     * Originate outgoing call to $destination, connecting on answer to $bridgeTarget
     * @return Result
     * */
    public function originate(OriginateTarget $destination, OriginateTarget $bridgeTarget, array $extraData = [])
    {
        $channel = $destination->getChannel();

        $keys = [];
        $vars = [];

        $originateAction = new \PAMI\Message\Action\OriginateAction($channel);
        $keys['action'] = 'Originate';
        $keys['channel'] = $channel;

        $originateAction->setCallerId($destination->getCallerid());
        $keys['callerid'] = $destination->getCallerid();

        $originateAction->setTimeout($destination->getTimeout()*1000); // To milliseconds
        $keys['timeout'] = $destination->getTimeout()*1000; // To milliseconds

/*
         if ($bridgeTarget->getType() == 'dialplan') {
             $originateAction->setContext($bridgeTarget->getContext());
             $originateAction->setExtension($bridgeTarget->getExtension());
             $originateAction->setPriority("1");
         } elseif ($bridgeTarget->getType() == 'number') {
             $originateAction->setApplication($bridgeTarget->getApplication());
             $originateAction->setData($bridgeTarget->getData());
         }
*/

        // Set pbx- variables from extraData
        foreach ($extraData as $k => $v) {
            if (substr(strtolower($k), 0, 4) == 'pbx-') {
                $originateAction->setVariable($k, $v);
                $vars[$k] = $v;
            }
        }

        $originateAction->setContext($this->originateContext);
        $keys['context'] = $this->originateContext;
        // EXTEN will be set later
        $originateAction->setPriority("1");
        $keys['priority'] = '1';

        $originateAction->setVariable('BRIDGE-TARGET', $bridgeTarget->getType());
        $vars['BRIDGE-TARGET'] = $bridgeTarget->getType();

        if ($bridgeTarget->getType() == 'number') {
            $originateAction->setVariable('BRT-NUM', $bridgeTarget->getNumber());
            $vars['BRT-NUM'] = $bridgeTarget->getNumber();
            $originateAction->setExtension($bridgeTarget->getNumber()); // set EXTEN to number
            $keys['exten'] = $bridgeTarget->getNumber(); // set EXTEN to number
            if ($bridgeTarget->getCallerid()) {
                $originateAction->setVariable('BRT-CLID', $bridgeTarget->getCallerid());
                $vars['BRT-CLID'] = $bridgeTarget->getCallerid();
            }
        } elseif ($bridgeTarget->getType() == 'dialplan') {
            $originateAction->setVariable('BRT-CTX', $bridgeTarget->getContext());
            $vars['BRT-CTX'] = $bridgeTarget->getContext();
            $originateAction->setVariable('BRT-EXTEN', $bridgeTarget->getExtension()); // set EXTEN to exten
            $vars['BRT-EXTEN'] = $bridgeTarget->getExtension(); // set EXTEN to exten
            $originateAction->setExtension($bridgeTarget->getExtension());
            $keys['exten'] = $bridgeTarget->getExtension();
        }

        $originateAction->setAsync(true);
        $keys['async'] = 'true';

        // Create OriginatedCall
        $origCall = new OriginatedCall($destination, $bridgeTarget, $extraData);
        $origCall->setStatus("originated");

        $origCallsPool = OriginatedCallsPool::getInstance();
        $origCallsPool->add($origCall);

        $originateAction->setActionID($origCall->getId());
        $keys['actionid'] = $origCall->getId();

        $originateAction->setVariable('API-CALL-ID', $origCall->getId());
        $vars['API-CALL-ID'] = $origCall->getId();

        $this->pamiClient->send($originateAction);

//        print_r($originateAction->getKeys());
//        print_r($keys);
//        print_r($originateAction->getVariables());
//        print_r($vars);

        // Emit event
        $evtKeys = [];
        $evtKeys['destination'] = $destination->getKeys();
        $evtKeys['bridge-target'] = $bridgeTarget->getKeys();
        $evtKeys['id'] = $origCall->getId();
        $evtKeys['extra-data'] = $extraData;

        $evt = new Event('originate.init', $evtKeys);
        EventEmitter::getInstance()->emit($evt);

        return new ResultOK(['id' => $origCall->getId()]);
    }
}
