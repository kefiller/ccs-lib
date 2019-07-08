<?php

namespace CCS\pbx;

use CCS\Logger;

class Asterisk_ami
{
    private $conn;
    private $bLogined = false;
    private $debug = 0;

    private $host;
    private $port;
    private $login;
    private $password;

    private $lastActionID;
    private $sleep_time;

    public function __construct($host, $port, $username, $password)
    {
        $this->host = $host;
        $this->port = $port;

        $this->login = $username;
        $this->password = $password;

        $this->lastActionID = 0;
        $this->sleep_time = 20000;  // задержка при чтении из сокета 1кк = 1с
    }

    public function __destruct()
    {
        $this->logoff();
        $this->disconnect();
    }

    public function connect()
    {
        $this->conn = fsockopen($this->host, $this->port, $a, $b, 10);
        if ($this->conn) {
            stream_set_timeout($this->conn, 0, 100000);
            fread($this->conn, 1024);
            return true;
        }
        return false;
    }

    public function isConnected()
    {
        return $this->conn != false;
    }

    public function write($a)
    {
        if (!$this->conn) {
            return false;
        }

        $this->lastActionID = rand(10000000000000000, 99999999900000000);
        $result = fwrite($this->conn, "ActionID: ".$this->lastActionID."\r\n$a\r\n\r\n");
        if ($result == false) {
            Logger::log($this->host.": fwrite error");
            $this->conn = false;
            $this->bLogined = false;
            return false;
        }
        return $this->lastActionID;
    }

    public function sleepi()
    {
        usleep($this->sleep_time);
    }

    // Чтение блоков, разделенных \r\n\r\n из сокета
    // Возвращает массив чанков, разденных по \r\n\r\n. Чанк - массив ключ-значение.
    private function readsock()
    {
        if (!$this->conn) {
            return false;
        }

        // First try
        $s = fread($this->conn, 1024);

        $dEmptyReadCnt = 0; // Дабы не залипнуть в цикле
        $dMaxRead = 10;

        while (substr($s, -4) != "\r\n\r\n" && ($dEmptyReadCnt < $dMaxRead)) {
            usleep(20000); // 0.02 sec
            $data = fread($this->conn, 4096);
            if (!$data) {
                $dEmptyReadCnt++; // Пустой ответ
            } else {
                $dEmptyReadCnt = 0;
            }
            if ($this->debug == 1) {
                Logger::log($data);
            }
            $s .= $data;
        }

        $s = str_replace("'", "", $s);

        $ans = []; // возвращаемый массив

        $chunks = explode("\r\n\r\n", $s);
        foreach ($chunks as $chunk) {
            $strings = explode("\r\n", $chunk);
            $aChunk = [];

            foreach ($strings as $str) {
                $tokens = explode(": ", $str);
                if (count($tokens) < 2) {
                    continue;
                }
                if ($tokens[0] == "") {
                    continue;
                }

                $sKey = trim($tokens[0]);
                $sVal = trim($tokens[1]);
                $aChunk[$sKey] = $sVal;
            }
            if (!empty($aChunk)) {
                $ans[] = $aChunk;
            }
        }
        return $ans;
    }

    // Чтение ответа от Астера
    public function read($actionid = '', $key = '', $value = '')
    {
        if (!$this->conn) {
            return false;
        }

        $dTimeoutSecs = 10;

        // Проверять ли пару ключ\значение или ключ
        $bFoundStr = true;
        if ($key != '' || $value != '') {
            $bFoundStr = false;
        }

        $tmStart = time();

        $ans = []; // возвращаемый массив

        while (!$bFoundStr && (time() - $tmStart) <= $dTimeoutSecs) {
            $chunks = $this->readsock();
            if (!$chunks || !is_array($chunks)) {
                return false;
            }

            if (!count($chunks)) {
                Logger::log("tmStart = $tmStart, time() = ".time().", time() - $tmStart = ".(time() - $tmStart));
                $this->sleepi();
                continue;
            }

            // Если не надо делать никаких проверок
            if ($actionid == '' && $key == '' && $value == '') {
                return $chunks;
            }

            foreach ($chunks as $chunk) {
                $bActionIdOk = ($actionid == '')?true:false; // Проверять ли ActionID
                foreach ($chunk as $k => $v) {
                    // Если надо проверить соответствие ActionID
                    if (!$bActionIdOk && $k == 'ActionID' && $v == $actionid) {
                        $bActionIdOk = true;
                        continue;
                    }
                    // Если надо искать пару ключ\значение или заданый ключ
                    if (!$bFoundStr) {
                        if ($key != '' && $value != '') { // найти ключ и значение
                            if ($k == $key && $v == $value) {
                                $bFoundStr = true;
                                continue;
                            }
                        } else {  // найти ключ
                            $bFoundStr = true;
                            continue;
                        }
                        continue;
                    }
                }
                if ($bActionIdOk) {
                    $ans[] = $chunk;
                }
            }
        }

        if ((time() - $tmStart) > $dTimeoutSecs) {
            Logger::log($this->host.": socket read error - timeout");
        }

        //error_log(print_r($ans, true));
        return $ans;
    }

    public function login()
    {
        if (!$this->conn) {
            return false;
        }

        $str = "Action: Login\r\nUsername: " . $this->login . "\r\nSecret: " . $this->password ."\r\nEvents: off";
        $actionID = $this->write($str);
        if (!$actionID) {
            return false;
        }

        $ans = $this->read($actionID, 'Message');
        if (!$ans || !isset($ans[0]) || $ans[0]['Message'] != 'Authentication accepted') {
            return false;
        }

        $this->bLogined = true;

        return true;
    }

    public function isLogined()
    {
        return $this->bLogined;
    }

    public function logoff()
    {
        if (!$this->conn || !$this->bLogined) {
            return false;
        }

        $actionID = $this->write("Action: Logoff");
        if (!$actionID) {
            return false;
        }

        $this->bLogined = false;
        $this->read($actionID, 'Message'); // ответ не важен
        return true;
    }

    public function reloadModule($module)
    {
        if (!$this->conn || !$this->bLogined) {
            return false;
        }

        $actionID = $this->write("Action: Reload\r\nModule: $module");
        if (!$actionID) {
            return false;
        }

        $ans = $this->read($actionID, 'Response');
        if (!$ans || !isset($ans[0]) || $ans[0]['Response'] != 'Success') {
            return false;
        }

        return true;
    }

    public function pauseQueueMember($sMember)
    {
        if (!$this->conn || !$this->bLogined) {
            return false;
        }
        // для паузы в конкретной очереди
        //$aster->write("Action: QueuePause\r\nQueue: ".$queue."\r\nInterface: sip/".$oper."\r\nPaused: false");

        $actionID = $this->write("Action: QueuePause\r\nInterface: sip/".$sMember."\r\nPaused: true");
        if (!$actionID) {
            return false;
        }

        $ans = $this->read($actionID, 'Response');
        if (isset($ans[0])) {
            return $ans[0];
        }
        return $ans;
    }

    public function unpauseQueueMember($sMember)
    {
        if (!$this->conn || !$this->bLogined) {
            return false;
        }

        $actionID = $this->write("Action: QueuePause\r\nInterface: sip/".$sMember."\r\nPaused: false");
        if (!$actionID) {
            return false;
        }

        $ans = $this->read($actionID, 'Response');
        if (isset($ans[0])) {
            return $ans[0];
        }
        return $ans;
    }

    public function queues()
    {
        if (!$this->conn || !$this->bLogined) {
            return false;
        }

        $actionID = $this->write("Action: Queues");
        if (!$actionID) {
            return false;
        }

        return $this->read();
    }

    public function ping()
    {
        if (!$this->conn || !$this->bLogined) {
            return false;
        }
        $actionID = $this->write("Action: Ping");

        $ans = $this->read($actionID, 'Response');
        if (!$ans || !isset($ans[0]) || $ans[0]['Response'] != 'Success') {
            return false;
        }

        return true;
    }

    public function sipPeers()
    {
        if (!$this->conn || !$this->bLogined) {
            return false;
        }
        $actionID = $this->write("Action: SIPpeers");

        // read until "Event: PeerlistComplete"
        //$this->s = 1;
        $ans = $this->read($actionID, 'Event', 'PeerlistComplete');
        //$this->s = 0;
        if (!$actionID) {
            return false;
        }

        return $ans;
    }

    public function coreShowChannels()
    {
        if (!$this->conn || !$this->bLogined) {
            return false;
        }

        $actionID = $this->write("Action: CoreShowChannels");
        if (!$actionID) {
            return false;
        }

        // Read until Event: CoreShowChannelsComplete
        return $this->read($actionID, 'Event', 'CoreShowChannelsComplete');
    }

    public function disconnect()
    {
        if ($this->conn) {
            fclose($this->conn);
        }
    }
}
