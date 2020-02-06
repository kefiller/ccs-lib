<?php

namespace CCS\db;

use CCS\Logger;
use CCS\Result;
use CCS\ResultOK;
use CCS\ResultError;

class MyDB
{
    private $conn;
    private $dbServers;//['dcccsapp.guo.local'   => '5432'];
    private $connParams;//['dbname' => 'ccs', 'user' => 'aster', 'password' => '12Fcnthbcr34',
    // 'connect_timeout' => '3'];
    private $quiet = true;
    private $reconnectOnFailure = true;

    // times try to reconnect DB
    private $reconnectAttempts = 3;
    // interval between DB reconnect attempts
    private $reconnectRetryIntvl = 1; // seconds

    // times retry query
    private $queryRetryAttempts = 3;
    // interval between retry query
    private $queryRetryIntvl = 1; // seconds
    // current query retry counter
    private $curQueryRetryCnt = 0;


    /** @var MyDB */
    private static $instance = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init(array $dbServers, array $connParams, $quiet = true, $reconnectOnFailure = true)
    {
        $this->dbServers = $dbServers;
        $this->connParams = $connParams;
        $this->quiet = $quiet;
        $this->reconnectOnFailure = $reconnectOnFailure;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    public function disconnect()
    {
        if ($this->conn) {
            @pg_close($this->conn);
        }
    }

    /**
     * @return Result
     */
    public function connect()
    {
        if (!is_array($this->dbServers) || !is_array($this->connParams)
         || empty($this->dbServers) || empty($this->connParams)) {
            return new ResultError("Invalid connection parameters - missing or empty");
        }

        $dbname   = $this->connParams['dbname'];
        $user     = $this->connParams['user'];
        $password = $this->connParams['password'];
        $connect_timeout = $this->connParams['connect_timeout'];

        foreach ($this->dbServers as $host => $port) {
            $pgConnString = "host=$host port=$port dbname=$dbname user=$user
             password=$password connect_timeout=$connect_timeout";
            if (!$this->quiet) {
                Logger::log("DB: Connecting to $host:$port...");
            }
            // @phan-suppress-next-line PhanUndeclaredConstant
            $this->conn = pg_connect($pgConnString, PGSQL_CONNECT_FORCE_NEW);
            if ($this->conn == false) {
                if (!$this->quiet) {
                    Logger::log("DB: could not connect to $host:$port");
                }
                continue;
            }
            if (!$this->quiet) {
                Logger::log("DB: connected successfully to $host:$port");
            }
            $aRes = $this->query("select pg_is_in_recovery()");
            if (!isset($aRes[0]['pg_is_in_recovery'])) {
                if (!$this->quiet) {
                    Logger::log("Empty result when asking for pg_is_in_recovery(), error: ".pg_last_error($this->conn));
                }
                continue;
            }
            if ($aRes[0]['pg_is_in_recovery'] == 't') {
                if (!$this->quiet) {
                    Logger::log("DB: $host:$port is in recovery mode");
                }
                continue;
            }
            if (!$this->quiet) {
                Logger::log("DB: Using $host:$port as primary server");
            }
            break;
        }
        if ($this->conn == false) {
            return new ResultError("Could not find any suitable server");
        }
        return new ResultOK();
    }

    public function query($sql)
    {
        $result = @pg_query($this->conn, $sql);
        if (!$result) {
            $sErr = pg_last_error($this->conn);
            if (!$this->quiet) {
                Logger::log("SQL ERROR: '$sErr', QUERY: '$sql'");
            }
            if (strpos($sErr, 'syntax error') !== false) {
                return false;
            }
            if (!$this->reconnectOnFailure) {
                if (!$this->quiet) {
                    Logger::log("DB: not reconnecting on failed query");
                }
                return false;
            }
            if ($this->curQueryRetryCnt >= $this->queryRetryAttempts) {
                if (!$this->quiet) {
                    Logger::log("DB: all query retries left");
                }
                $this->curQueryRetryCnt = 0;
                return false;
            }
            if (!$this->quiet) {
                Logger::log("DB: reconnect db...");
            }
            $connOK = false;
            for ($i=0; $i < $this->reconnectAttempts; $i++) {
                $result = $this->connect();
                if ($result->error()) {
                    if (!$this->quiet) {
                        Logger::log("DB: reconnect error - " . $result->errorDesc());
                    }
                    sleep($this->reconnectRetryIntvl);
                    continue;
                }
                $connOK = true;
                break;
            }
            if (!$connOK) {
                if (!$this->quiet) {
                    Logger::log("DB: all reconnect tries left, abort query");
                }
                return false;
            }

            $this->curQueryRetryCnt++;
            sleep($this->queryRetryIntvl);

            return $this->query($sql);
        }
        $aResult = array();
        if (!pg_num_rows($result)) {
            return $aResult;
        }
        while ($row = pg_fetch_assoc($result)) {
            $aResult[]  = $row;
        }

        return $aResult;
    }

    // Сериализация массива данных
    public function serializeEntity($table, $sType, $aEntity)
    {
        foreach ($aEntity as $k => $v) {
            $sName = strtolower($k);
            $sJSON = json_encode($v, JSON_PRETTY_PRINT);
            $sQuery = "select count(*) from $table where s_type='$sType' and s_name = '$sName'";
            $aRet = $this->query($sQuery);
            $dCnt = 0;
            if (isset($aRet[0]['count'])) {
                $dCnt = $aRet[0]['count'];
            }
            if ($dCnt == 0) {
                $sQuery = "insert into $table(s_type,s_name,s_def) values('$sType','$sName','$sJSON')";
            } else {
                $sQuery = "update $table set s_def = '$sJSON' where s_type='$sType' and s_name = '$sName'";
            }
            $this->query($sQuery);
        }
        return true;
    }

    public function deserializeEntity($table, $sType, $sName = '')
    {
        $sQuery = "select s_name,s_def from $table where s_type='$sType'";
        if ($sName != '') {
            $sQuery .= " and s_name ='$sName'";
        }

        $aResult = [];
        $aRet = $this->query($sQuery);
        foreach ($aRet as $aRow) {
            if (isset($aRow['s_name'])) {
                $sName = strtolower($aRow['s_name']);
            }
            if (isset($aRow['s_def'])) {
                $sJSON = $aRow['s_def'];
            }
            if ($sName == '' || $sJSON == '') {
                continue;
            }
            $aDef = json_decode($sJSON, true);
            if ($aDef == null) {
                continue;
            }
            $aResult[$sName] = $aDef;
        }
        return $aResult;
    }

    public function tableExist($table)
    {
        $rslt = $this->query("SELECT to_regclass('public.$table') is NULL as exist");
        if (!isset($rslt[0]['exist'])) {
            return false;
        }

        return $rslt[0]['exist'] != 't';
    }

    private function __clone()
    {
    }

    private function __construct()
    {
    }
}
