<?php

namespace CCS\a2i;

use CCS\Logger;
use CCS\db\MyDB;
use CCS\util\_;

use \Clue\React\Ami\Factory;
use \Clue\React\Ami\Client;
use \Clue\React\Ami\ActionSender;
use \Clue\React\Ami\Protocol\Response;
use \Clue\React\Ami\Protocol\Event;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, PSR2.Classes.PropertyDeclaration.Underscore
class AMI
{
    /** @var string */
    private $_campaign = '';

    private $_servers = [];

    /** @var MyDB|null */
    private $_db = null;

    private $_context = 'a2in';           // asterisk context
    private $_originateTimeout = '40000'; // 40 sec

    private $_bInited = false;

    /** @var object|null */
    private $_amiLoop    = null;

    /** @var \Clue\React\Ami\Factory|null */
    private $_amiFactory = null;

    private $_evtListener = null;
    private $_mainLoop    = null;
    private $_onError     = null;

    private $_amiClients  = [];
    private $_amiSenders  = [];

    /** @var string */
    private $trunkLocTbl;

    public function __construct(
        $campaign,
        $servers,
        MyDB $db,
        string $trunkLocTbl,
        callable $mainLoop,
        callable $evtListener,
        callable $onError
    ) {
        $this->_campaign   = $campaign;
        $this->_servers    = $servers;
        $this->_db         = $db;
        $this->trunkLocTbl = $trunkLocTbl;

        $this->_mainLoop    = $mainLoop;
        $this->_evtListener = $evtListener;
        $this->_onError     = $onError;
    }

    public function run()
    {
        if (!is_array($this->_servers) || count($this->_servers) < 1) {
            Logger::log("AMI: _servers is not configured");
            return false;
        }
        if (!$this->_campaign || !$this->_db) {
            Logger::log("AMI: _campaign or _db is not set");
            return false;
        }

        Logger::log("AMI: trying to init AMI clients...");

        $this->_amiLoop = \React\EventLoop\Factory::create();
        $this->_amiFactory = new Factory($this->_amiLoop);

        // @phan-suppress-next-line PhanUnusedVariableValueOfForeachWithKey
        foreach ($this->_servers as $host => $params) {
            if (!$this->initAMIClient($host)) {
                Logger::log("AMI: error init AMI client for host $host");
                return false;
            }
        }

        // Init event loop
        $this->_amiLoop->run();
        Logger::log("AMI: AMI clients configured OK");
        return true;
        /*
                $this->_amiSender->listCommands()->then(function (Response $response) {
                    echo 'Available commands:' . PHP_EOL;
                    //var_dump($response);
                });
        */
    }

    private function initAMIClient($host)
    {
        $params = $this->_servers[$host];

        if (!_::hasMandatoryKeys(['port','username','password'], $params)) {
            Logger::log("AMI: Invalid params for host $host");
            return false;
        }
        $ip = gethostbyname($host);

        $this->_amiFactory->createClient($params['username'].':'.$params['password'].'@'.$ip)->then(
            function (Client $client) use ($host) {
                $this->_amiClients[$host] = $client;
                $this->onClientCreated($host);
                // если подключились уже ко всем серверам
                if (count($this->_amiClients) == count($this->_servers) && !$this->_bInited) {
                    $this->_bInited = true;
                    // запустим основной цикл
                    // @phan-suppress-next-line PhanUnusedClosureParameter
                    $this->_amiLoop->addPeriodicTimer(0.5, function (\React\EventLoop\Timer\Timer $timer) {
                        call_user_func($this->_mainLoop);
                    });
                }
            },
            function (\Exception $error) use ($host) {
                if (!$this->_bInited) {
                    $this->error("AMI: Error connect to $host - '".$error->getMessage()."'");
                } else {
                    Logger::log("AMI: connection error, waiting for reconnect...");
                    sleep(5);
                    $this->initAMIClient($host);
                }
            }
        );
        return true;
    }

    private function error($reason)
    {
        call_user_func($this->_onError, $reason);
    }

    private function onClientCreated($host)
    {
        Logger::log("AMI: connection established with $host");

        $amiClient = $this->_amiClients[$host];
        $amiSender = new ActionSender($amiClient);

        // Configure filtering
        $fltParams = ['Operation' => 'Add', 'Filter' => 'Event: OriginateResponse'];
        $amiClient->request($amiClient->createAction('Filter', $fltParams));/*->then(function (Response $response) {
//            Logger::log("AMI: event filter configured");
//            // Start event listener after that
//            $this->initEvtListener();
//        });*/

        $fltParams = ['Operation' => 'Add', 'Filter' => 'Event: UserEvent'];
        $amiClient->request($amiClient->createAction('Filter', $fltParams))->then(
            //@phan-suppress-next-line PhanUnusedClosureParameter
            function (Response $response) use ($host) {
                Logger::log("AMI: event filter configured for $host");
                // Start event listener after that
                $this->initEvtListener($host);
            }
        );

        // Configure on_conn_close callback
        $amiClient->on('close', function () use ($host) {
            Logger::log("AMI: connection with $host closed ,waiting for reconnect...");
            sleep(5);
            $this->initAMIClient($host);
        });

        // Configure on_conn_error callback
        $amiClient->on('error', function () use ($host) {
            Logger::log("AMI: connection error with $host, waiting for reconnect...");
            sleep(5);
            $this->initAMIClient($host);
        });

        $this->_amiClients[$host] = $amiClient;
        $this->_amiSenders[$host] = $amiSender;
    }

    private function initEvtListener($host)
    {
        // Configure event listener
        $this->_amiClients[$host]->on('event', function (Event $event) {
            call_user_func($this->_evtListener, $event);
        });
    }

    public function originate(
        string $number,
        string $trunk,
        string $callerid,
        callable $onSuccess,
        callable $onError,
        array $vars = [],
        string $cardID = ''
    ) {
        if ($number == '' || $trunk == '' || $callerid == '') {
            return false;
        }

        $host = $this->findTrunkHost($trunk);
        if (!$host) {
            call_user_func($onError, "AMI: could not find host for trunk $trunk");
            return false;
        }

        $suff = '';
        if ($cardID != '') { // если atk
            $suff = "^$callerid^$cardID";
        }

        $callParams = [
            'Channel' => "SIP/$trunk/$number{$suff}",
            'Callerid' => "\"$callerid\" <$callerid>",
            'Timeout' => $this->_originateTimeout,
            'Context' => $this->_context,
            'Exten'   => "$number^{$this->_campaign}",
            'Priority' => '1',
            'Async' => 'Yes',
        ];

        $callParams['Variable'] = [];
        // Could not use it for now. We have no idea how to determine
        // where call belongs to in OriginateResponse...
        //$callParams['Variable']['CAMPAIGN'] = $this->_campaign;

        foreach ($vars as $k => $v) {
            if ($v) {
                $callParams['Variable'][$k] = $v;
            }
        }

        $amiClient = $this->_amiClients[$host];
        $action = $amiClient->createAction('Originate', $callParams);
        $amiClient->request($action)->then(function (Response $response) use ($onSuccess) {
            //Logger::log("AMI: origination OK");
            //print_r($response);
            call_user_func($onSuccess, $response);
        }, function (\Exception $error) use ($onError) {
            //Logger::log("AMI: origination error: '".$error->getMessage()."'");
            call_user_func($onError, $error);
        });
    }

    private function findTrunkHost($trunk)
    {
        $query = "select location from {$this->trunkLocTbl} where trunk = '$trunk'";
        $result = $this->_db->query($query);
        if (!count($result)) {
            return false;
        }
        if (!isset($result[0]['location'])) {
            return false;
        }
        $location = $result[0]['location'];
        if (!isset($this->_servers[$location])) {
            return false;
        }

        return $location;
    }
}
