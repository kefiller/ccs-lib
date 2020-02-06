<?php

namespace CCS\a2i;

// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore
class DBLogger
{
    private $db;
    private $logTbl;

    public function __construct(&$db, $logTbl)
    {
        $this->db = $db;
        $this->logTbl = $logTbl;
    }

    public function log($campaign, $number, $logRecord)
    {
        // quick & dirty...
        $campaign = str_replace('a2i_campaign_', '', $campaign);
        $campaign = strtolower("a2i_campaign_$campaign");

        $sJSON = json_encode($logRecord, JSON_PRETTY_PRINT);
        $this->db->query("insert into ".$this->logTbl."(s_campaign, s_number, s_def)
         values('".$campaign."','$number','$sJSON')");
    }

    public function getLogTable()
    {
        return $this->logTbl;
    }
}
