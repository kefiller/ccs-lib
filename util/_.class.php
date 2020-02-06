<?php

namespace CCS\util;

class _
{
    public static function email($from, $to, $subject, $msg, $srvHost, $srvPort)
    {
        if ($from == '' || $to == '' || $subject == '' || $msg == '' || $srvHost == '' || $srvPort == '') {
            return false;
        }

        $headers  = [];
        $smtpinfo = [];

        $headers['From']    = $from;
        $headers['To']      = $to;
        $headers['Subject'] = $subject;

        $body = $msg;

        $smtpinfo["host"] = $srvHost;
        $smtpinfo["port"] = $srvPort;
        $smtpinfo["auth"] = false;

        // Create the mail object using the Mail::factory method
        //@phan-suppress-next-line PhanUndeclaredClassMethod
        $mail_object = \Mail::factory("smtp", $smtpinfo);
        $mail_object->send($to, $headers, $body);
        return true;
    }

    public static function guidv4()
    {
        $data = random_bytes(16);

        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function hasMandatoryKeys($mandKeys, $keys)
    {
        foreach ($mandKeys as $mandKey) {
            if (!isset($keys[$mandKey]) || $keys[$mandKey] === '') {
                return false;
            }
        }
        return true;
    }

    private static function out($str)
    {
        $now = date("Y-m-d H:i:s");
        if (php_sapi_name() == "cli") {
            // In cli-mode
            echo "$now $str\r\n";
        } else {
            // Not in cli-mode
            error_log("$now $str");
        }
    }

    private static function fork()
    {
        // Detach from console
        $child_pid = pcntl_fork();
        if ($child_pid) {
            // Exit parent process
            exit(0);
        }
        // Make child process main
        posix_setsid();
    }

    private static function redirStreams($fName)
    {
        global $STDIN, $STDOUT, $STDERR;
        fclose(STDIN);
        fclose(STDOUT);
        fclose(STDERR);

        $STDIN  = fopen('/dev/null', 'r');
        $STDOUT = fopen(dirname(__FILE__)."/log/$fName.log", 'ab');
        $STDERR = fopen(dirname(__FILE__)."/log/{$fName}_error.log", 'ab');
    }
}
