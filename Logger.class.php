<?php

namespace CCS;

class Logger
{
    /** @var bool */
    private static $logTime = false;

    public static function setLogTime(bool $logTime)
    {
        self::$logTime = $logTime;
    }

    public static function log($str)
    {
        if (self::$logTime) {
            echo date("Y-m-d H:i:s") . " " . $str . "\n";
        } else {
            echo $str . "\n";
        }
    }
}
