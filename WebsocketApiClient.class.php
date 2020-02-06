<?php

namespace CCS;

use \Swoole\Coroutine as co;

/** @phan-file-suppress PhanUndeclaredClassMethod, PhanUndeclaredFunction */

class WebsocketApiClient extends IApiClient
{
    private $wsClient;

    /** @var bool */
    private $ssl;

    /** @var Callable */
    private $onEventFunc;

    /** @var Callable */
    private $onCloseFunc;

    /** @var Callable */
    private $onErrorFunc;

    /** var \Swoole\Coroutine\Channel */
    private $coChan;

    /** @var string */
    private $status = "";

    /** @var float */
    private $timeout = 5.0;

    /** @var float */
    private $IOPollIntvl = 0.01;

    /** @var float */
    private $heartbeatIntvl = 10.0;

    private $wsLock = false; // websocket is locked when reading/writing

    public function __construct(string $address, int $port, string $authToken, bool $ssl = false, $connectTimeout = 2.0)
    {
        if (!$address) {
            throw new \Exception("Empty address");
        }
        if (!$port) {
            throw new \Exception("Empty port");
        }

        $this->ssl = $ssl;

        if ($ssl) {
            $protoPref = "https";
        } else {
            $protoPref = "http";
        }

        $url = "$protoPref://{$address}:{$port}/api/v1/";

        parent::__construct($url, $authToken);

        $this->wsClient = new co\http\Client($address, $port, $ssl);
        $this->wsClient->set(array(
            'timeout' => $connectTimeout,
            'websocket_mask' => true,
        ));
    }

    /**
     * @return boolean
     */
    private function wsPush($data)
    {
        // try to push to websocket conn
        $cnt = 100;
        $pushOK = false;
        for ($i = 0; $i < $cnt; $i++) {
            if ($this->wsLock) {
                co::sleep($this->IOPollIntvl/10);
                continue;
            }
            $this->wsLock = true; // acquire lock
            $pushOK = $this->wsClient->push($data);
            $this->wsLock = false; // release lock
            break;
        }
        return $pushOK;
    }

    /**
     * @return Result
     */
    public function push(array $request = [])
    {
        if (!$this->isConnected()) {
            return new ResultError("Not connected");
        }

        if (empty($request)) {
            $request = $this->req;
        }

        $jsonRequest = json_encode($request, JSON_PRETTY_PRINT);

        $pushOK = $this->wsPush($jsonRequest);

        if (!$pushOK) {
            $this->status = "disconnected";
            $this->wsClient->close();
            if ($this->onCloseFunc) {
                // call callback in coro
                go(function () {
                    ($this->onCloseFunc)();
                });
            }
            return new ResultError("Connection closed in push");
        }

        return new ResultOK("Request pushed OK");
    }

    /** @return Result */
    public function connect()
    {
        $this->coChan = new co\Channel(1);
        go(function () {
            // Connect and upgrade to Websocket protocol
            $ret = $this->wsClient->upgrade("/");
            if (!$ret) {
                $this->coChan->push(['connection-status' => "disconnected"]);
                return;
            }
            $this->coChan->push(['connection-status' => "connected"]);
        });

        $connStatus = $this->coChan->pop($this->timeout);
        $this->status = $connStatus['connection-status'] ?? '';

        if ($this->status != "connected") {
            return new ResultError("connection failed");
        }

        // run event listener
        go(function () {
            while (true) { // Listen for incoming data
                co::sleep($this->IOPollIntvl);

                if (!$this->isConnected()) { // nothing to do
                    return;
                }

                // else connected

                if ($this->wsLock) {
                    continue;
                }

                $this->wsLock = true; // acquire lock
                $data = $this->wsClient->recv($this->IOPollIntvl);
                $this->wsLock = false; // release lock
                if ($data) {
                    $this->handleMessage($data);
                }
            }
        });

        // run heartbeat coro
        go(function () {
            while (true) {
                $cnt = 1000.0;
                for ($i = 0; $i < $cnt; $i++) {
                    co::sleep($this->heartbeatIntvl/$cnt);
                    if (!$this->isConnected()) { // nothing to do
                        return;
                    }
                }
                $this->req['method'] = "system.ping";
                unset($this->req['params']);
                $this->push();
            }
        });

        return new ResultOK();
    }

    public function close()
    {
        return $this->wsClient->close();
    }

    public function disconnect()
    {
        $this->status = "disconnected";
    }

    public function isConnected()
    {
        return $this->status == "connected";
    }

    public function onEvent(callable $func)
    {
        $this->onEventFunc = $func;
    }

    public function onClose(callable $func)
    {
         $this->onCloseFunc =  $func;
    }

    public function onError(callable $func)
    {
        $this->onErrorFunc =  $func;
    }

    private function handleMessage($frame)
    {
        $frameData = $frame->data ?? '';

        if (!$frameData) {
            if ($this->onErrorFunc) {
                // call callback in coro
                go(function () use ($frame) {
                    ($this->onErrorFunc)("No data in frame:" . print_r($frame, true));
                });
            }
            return;
        }

        $ans = @json_decode($frameData, true);
        if ($ans === null) {
            if ($this->onErrorFunc) {
                // call callback in coro
                go(function () use ($frameData) {
                    ($this->onErrorFunc)("Could not decode answer: " . $frameData);
                });
            }
            return;
        }

        if (isset($ans['event'])) {
            if ($this->onEventFunc) {
                // call callback in coro
                go(function () use ($ans) {
                    ($this->onEventFunc)($ans);
                });
            }
            return;
        }

        // Else it's response to request
        if ($this->coChan->isFull()) {
            // Clear queue
            $this->coChan->pop($this->IOPollIntvl);
        }
        $this->coChan->push(['request-response' => $ans]);
    }

    public function eventsSubscribe(array $events)
    {
        $this->req['method'] = "events.subscribe";
        $this->req['params'] = ['events' => $events ];

        $rslt = $this->push();
        if ($rslt->error()) {
            return $rslt;
        }

        return new ResultOK("Subscription OK");
    }

    public function eventsUnsubscribe(array $events)
    {
        if (!$this->isConnected()) {
            return new ResultError("Not connected");
        }

        $this->req['method'] = "events.unsubscribe";
        $this->req['params'] = ['events' => $events ];

        $rslt = $this->push();
        if ($rslt->error()) {
            return $rslt;
        }

        return new ResultOK("Unsubscription OK");
    }
}
