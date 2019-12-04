<?php

namespace CCS\a2i;

use CCS\ResultError;
use CCS\ResultOK;
use CCS\Result;
use CCS\db\MyDB;

class A2ICampMgr
{
    /** @var MyDB */
    private $db;
    private $lastErrMsg;
    private $logger;
    private $dtFormat = 'Y-m-d H:i:s';
    private $callDPath; //'/srv/ccs-a2id/a2i-calld.php';

    /** @var A2ICampMgr */
    private static $instance = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init($callDPath, $logger)
    {
        $this->callDPath = $callDPath;
        $this->logger = $logger;
        $this->db = MyDB::getInstance();
    }

    public function getLastErrMsg()
    {
        return $this->lastErrMsg;
    }

    // convert campaign name from 'test' to 'a2i_campaign_test'
    private function fixCampName($campaign)
    {
        // quick & dirty...
        $campaign = str_replace('a2i_campaign_', '', $campaign);
        return strtolower("a2i_campaign_$campaign");
    }

    // Возвращает список кампаний
    public function list()
    {
        $result = $this->db->query("select s_campaign from a2i_campaigns order by s_campaign");
        if (!$result) {
            return [];
        }
        $ret = [];
        foreach ($result as $arr) {
            $campName = $arr['s_campaign'];
            $ret[] = str_replace('a2i_campaign_', '', $campName);
        }
        return $ret;
    }

    // Возвращает настройки для кампании
    public function getSettings($campaign)
    {
        $campaign = $this->fixCampName($campaign);

        if (!$this->exists($campaign)) {
            $this->lastErrMsg = "Campaign table ".$campaign.", does not exists";
            return false;
        }
        $aRet = $this->db->deserializeEntity($campaign, 'settings');
        return $aRet['settings'];
    }

    // Возвращает статус кампании
    public function getStatus($campaign)
    {
        $campaign = $this->fixCampName($campaign);

        if (!$this->exists($campaign)) {
            $this->lastErrMsg = "Campaign table ".$campaign.", does not exists";
            return false;
        }

        return $this->db->deserializeEntity($campaign, 'status')['status'];
    }

    // Создает пустую кампанию с настройками из dataFile
    public function createCampaign($campaign, $campaignSettings)
    {
        $campaign = $this->fixCampName($campaign);

        if ($this->exists($campaign)) {
            $this->lastErrMsg = "Could not create campaign ".$campaign . ", campaign already exists";
            return false;
        }

        // Создадим таблицу
        if ($this->db->query("create table ".$campaign." (
            s_id         serial,
            s_type       text NOT NULL,
            s_name       text NOT NULL,
            s_def        text NOT NULL
        )") === false) {
            $this->lastErrMsg = "Could not create campaign table ".$campaign;
            return false;
        }

        $this->db->query("delete from a2i_campaigns where s_campaign='".$campaign."'");
        $this->db->query("insert into a2i_campaigns(s_campaign) values('".$campaign."')");

        $campStatus = [];
        $campStatus['status'] = 'created';
        $campStatus['lastStatusUpdate'] = date($this->dtFormat);
        $this->db->serializeEntity($campaign, 'status', ['status' => $campStatus]);

        return $this->updateCampaign($campaign, $campaignSettings);
    }

    // Устанавливает настройки кампании из dataFile
    public function updateCampaign($campaign, $campSettings)
    {
        $campaign = $this->fixCampName($campaign);

        if (!$this->exists($campaign)) {
            $this->lastErrMsg = "Could not update campaign ".$campaign . ", campaign doesnt exists";
            return false;
        }

        $mandatoryKeys = ['interval-wtime','interval-dow','amount','retry','retry-secs','interval-send'];
        $campaignSettings = ['settings' => $campSettings];

        $campSetKeys = array_keys($campaignSettings['settings']);
        if (!$this->checkMandatoryKeys($mandatoryKeys, $campSetKeys)) {
            // отсутствуют некоторые обязательные поля
            $this->lastErrMsg = "Mandatory keys missing:".implode(',', $mandatoryKeys);
            return false;
        }
        /*
        // Параметры кампании по умолчанию
        $campaignSettings['default-settings'] =
                [
                    // интервал времени, когда можно отправлять сообщения
                    'interval-wtime' => '09:00:00-18:00:00',
                    // дни недели, когда можно отправлять сообщения
                    'interval-dow'   => 'mon-fri',
                    // срок действия кампании
                    'expire'         => '2017-02-20 23:59:59',
                    // общее кол-во сообщений, которое должно быть доставлено
                    'amount'         => '1',
                    // в случае ошибки доставки сообщения, кол-во попыток повторной отправки
                    'retry'          => '3',
                    // в случае ошибки доставки сообщения, интенвал повторной отправки
                    'retry-secs'     => '300',
                    // в случае, если надо доcтавить > 1 сообщения, интервал времени, через который их отправлять
                    'interval-send'  => 24*60*60,
                    // Шаблон сообщения для TTS
                    'msg-template'   => 'Привет ${number}, вы должны ${debt} денежек',
                    // ID карты (в случае звонков на atk)
                    'cardid' => '',
                    trunk => '',
                    callerid => '',
                    channels => 2,
                    // ************ Настройки TTS ************
                    // Режим генерации TTS.
                    // 'on-call' - перед вызовом (по умолчанию). TTS генерируется в процессе работы кампании,
                    // перед вызовом на конкретный номер.
                    // 'on-start' - при старте кампании. TTS генерируется при старте работы кампании, по всем номерам.
                    'tts-generate' => 'on-call',
                    // Остальные настройки подробнее тут: https://webasr.yandex.net/ttsdemo.html
                    // и тут:
                    // https://tech.yandex.ru/speechkit/cloud/doc/guide/common/speechkit-common-tts-http-request-docpage/
                    // Голос говорящего
                    'tts-speaker' => 'zahar',
                    // Скорость речи
                    'tts-speed' => '0.7',
                    // Ключ API
                    'tts-key' => '2b32b23c-8345-4b99-9c4a-831be65082f3',
                    // Эмоция
                    'tts-emotion' => 'evil',
                    // ************ Bridge-target ************
                    // После соединения с абонентом и (опционального) проигрывания сообщения,
                    // перейти в указанный bridge-target(номер абонента или контекст диалплана).
                    // Параметры bridge-target аналогичны методу API call.originate
                    'bridge-target' => ['type' => 'number', 'number' => '2860'],
                    // или
                    'bridge-target' => ['type' => 'dialplan', 'context' => 'internal', 'extension' => '9998'],
                ];
        */
        $this->db->serializeEntity($campaign, 'settings', $campaignSettings);
        if ($this->logger) {
            $this->logger->log($campaign, "", ['type' => 'create', 'settings' => $campaignSettings]);
        }
        return true;
    }

    // Проверяет наличие кампании $campaign
    public function exists($campaign)
    {
        $campaign = $this->fixCampName($campaign);

        if (!$this->db->tableExist($campaign)) {
            return false;
        }

        $aCamps = $this->db->query("select s_campaign from a2i_campaigns where s_campaign='".$campaign."'");
        if (!count($aCamps)) {
            return false;
        }
        return true;
    }

    // Удаляет кампанию $campaign
    public function dropCampaign($campaign)
    {
        $campaign = $this->fixCampName($campaign);

        if (!$this->exists($campaign)) {
            $this->lastErrMsg = "Could not drop campaign ".$campaign.", no such campaign exists";
            return false;
        }

        $campStatus = $this->getStatus($campaign);
        // @phan-suppress-next-line PhanTypeArraySuspicious
        if (isset($campStatus['status']) && $campStatus['status'] == 'running') {
            $this->lastErrMsg = "Could not drop campaign $campaign: campaign is running";
            return false;
        }

        $this->db->query("drop table ".$campaign);
        $this->db->query("delete from a2i_campaigns where s_campaign='".$campaign."'");
        if ($this->logger) {
            $this->logger->log($campaign, "", ['type' => 'drop']);
        }

        return true;
    }


    // Удаляет кампании по маске $campaignPatt
    public function dropCampaigns($campaignPatt)
    {
        $campaignPatt = $this->fixCampName($campaignPatt);

        $aCamps = $this->db->query("select s_campaign from a2i_campaigns where s_campaign like '".$campaignPatt."'");
        if (!count($aCamps)) {
            $this->lastErrMsg = "Could not drop campaigns ".$campaignPatt.", no such records exist";
            return false;
        }

        $errTables = [];
        foreach ($aCamps as $campRow) {
            $camp = $campRow['s_campaign'];
            if (!$this->dropCampaign($camp)) {
                $errTables[] = $camp;
                continue;
            }
        }

        if (count($errTables)) {
            $this->lastErrMsg = "Could not drop campaigns:  ". implode(',', $errTables)
             . ", campaigns does not exist or running";
            return null;
        }

        return true;
    }

    // Добавляет в кампанию $campaign номера из $numbers
    public function addNumbers($campaign, $aNumbers)
    {
        $campaign = $this->fixCampName($campaign);

        if (!is_array($aNumbers)) {
            $this->lastErrMsg = "Invalid arguments: numbers is not array";
            return false;
        }

        if (!$this->exists($campaign)) {
            $this->lastErrMsg = "Campaign $campaign does not exists";
            return false;
        }

        $aStore = []; // for save in DB
        $aWrongNums = []; // for save in log

        $mandatoryKeys = ["number"];
        $errRows = '';
        foreach ($aNumbers as $aNumber) {
            // check for mandatory fields ($mandatoryKeys)
            $rowKeys = array_keys($aNumber);
            if (!$this->checkMandatoryKeys($mandatoryKeys, $rowKeys)) { // bad row
                $errRows .= " [" . str_replace("\n", '', var_export($aNumber, true)) . ": no mandatory field]";
                $aWrongNums[] = $aNumber;
                continue;
            }

            // check for valid number format [78]XXXXXXXXXX
            $num = $aNumber['number'];
            $firstDigit = substr($num, 0, 1);
//            if (!(($firstDigit == '7' || $firstDigit == '8') && strlen($num) == 11)) {
            if (!(($firstDigit == '7' || $firstDigit == '8'))) {
                $errRows .= " [$num: wrong number]";
                $aWrongNums[] = $aNumber;
                continue; // skip number
            }

            $aStore[$num] = $aNumber;
        }
        $this->db->serializeEntity($campaign, 'number', $aStore);
        if ($this->logger) {
            $this->logger->log($campaign, "", ['type' => 'add', 'data' => $aStore, 'wrong-data' => $aWrongNums]);
        }

        if ($errRows) {
            $this->lastErrMsg = " following wrong records were not added: $errRows";
            return null;
        }

        return true;
    }

    // Удаляет из кампании $campaign номера из $aNumbers
    public function cutNumbers($campaign, $aNumbers)
    {
        $campaign = $this->fixCampName($campaign);

        if (!is_array($aNumbers)) {
            $this->lastErrMsg = "Invalid arguments: numbers is not array";
            return false;
        }

        if (!$this->exists($campaign)) {
            $this->lastErrMsg = "Campaign $campaign does not exists";
            return false;
        }

        $aStore = []; // for logging

        $errRows = '';
        foreach ($aNumbers as $number) {
            $aNumber = $this->db->deserializeEntity($campaign, 'number', $number);
            if (!count($aNumber)) {
                $errRows .= " $number";
                continue;
            }
            $aStore[$number] = $aNumber;
            $sql = "delete from ".$campaign." where s_type = 'number' and s_name = '$number'";
            $this->db->query($sql);
        }

        if ($this->logger) {
            $this->logger->log($campaign, "", ['type' => 'cut', 'data' => $aStore]);
        }
        if ($errRows) {
            $this->lastErrMsg = " following wrong records were not cut: $errRows";
            return null;
        }

        return true;
    }

    // Получает из кампании $campaign номера
    public function getNumbers($campaign)
    {
        $campaign = $this->fixCampName($campaign);

        if (!$this->exists($campaign)) {
            $this->lastErrMsg = "Campaign $campaign does not exists";
            return false;
        }

        return $this->db->deserializeEntity($campaign, 'number');
    }

    // Запускает кампанию $campaign
    public function start($campaign)
    {
        $campaign = $this->fixCampName($campaign);

        if (!$this->exists($campaign)) {
            $this->lastErrMsg = "Campaign $campaign does not exists";
            return false;
        }

        $campStatus = $this->getStatus($campaign);
        // @phan-suppress-next-line PhanTypeArraySuspicious
        if (isset($campStatus['status']) && $campStatus['status'] == 'running') {
            $this->lastErrMsg = "Campaign $campaign is already running";
            return false;
        }

        $output = [];
        $return_var = 0;
        if ($this->logger) {
            $this->logger->log($campaign, "", ['type' => 'start']);
        }
        exec($this->callDPath . " " . $campaign, $output, $return_var);
        return ['output' => $output, 'exit_code' => $return_var];
    }

    // Останавливает кампанию $campaign
    public function stop($campaign)
    {
        $campaign = $this->fixCampName($campaign);

        if (!$this->exists($campaign)) {
            $this->lastErrMsg = "Campaign $campaign does not exists";
            return false;
        }

        $campStatus = $this->getStatus($campaign);
        // @phan-suppress-next-line PhanTypeArraySuspicious
        if (isset($campStatus['status']) && $campStatus['status'] != 'running') {
            $this->lastErrMsg = "Campaign $campaign is not running";
            return false;
        }
        // @phan-suppress-next-line PhanTypeArraySuspicious
        $pid = isset($campStatus['pid'])?$campStatus['pid']:null;
        if (!$pid) {
            $this->lastErrMsg = "Could not get pid of campaign $campaign";
            return false;
        }
        // @phan-suppress-next-line PhanUndeclaredConstant
        if (!posix_kill($pid, SIGQUIT)) {
            $this->lastErrMsg = "Error sending SIGINT signal to pid $pid";
            return false;
        }
        if ($this->logger) {
            $this->logger->log($campaign, "", ['type' => 'stop']);
        }
        return true;
    }

    // Получает лог кампании $campaign
    public function getLog($campaign, $dateFrom = '', $dateTo = '', $limit = '')
    {
        $campaign = $this->fixCampName($campaign);

        if (!$this->logger) {
            $this->lastErrMsg = "logger not set";
            return false;
        }

        $logTbl = $this->logger->getLogTable();

        $sql = "select s_dt, s_number, s_def from $logTbl where s_campaign = '$campaign' ";
        if ($dateFrom) {
            $sql .= " and s_dt > '$dateFrom' ";
        } else {
            $sql .= " and s_dt > now()::date ";
        }
        if ($dateTo) {
            $sql .= " and s_dt < '$dateTo' ";
        } else {
            $sql .= " and s_dt < now() ";
        }
        $sql .= " order by s_dt ";

        if ($limit) {
            $sql .= " limit $limit ";
        } else {
            $sql .= " limit 1000 ";
        }

        echo "$sql\n";

        $ret = [];
        $rows = $this->db->query($sql);
        foreach ($rows as $row) {
            $ret[] = ['date' => $row['s_dt'], 'number' => $row['s_number'],
             'entry' => json_decode($row['s_def'], true) ];
        }
        return $ret;
    }

    /**
     * Returns path to TTS record
     * @return Result */
    public function getTTSRec(string $campaignName, string $number)
    {
        $campaign = $this->fixCampName($campaignName);
        if (!$this->exists($campaign)) {
            return new ResultError("No such campaign $campaignName");
        }

        $aNumber = $this->db->deserializeEntity($campaign, 'number', $number);
        if (!isset($aNumber[$number])) {
            return new ResultError("No such number $number");
        }
        if (!isset($aNumber[$number]['x-tts-rec'])) {
            return new ResultError("No TTS exists");
        }
        $ttsRec = $aNumber[$number]['x-tts-rec'];

        return new ResultOK($ttsRec);
    }


    /**
     * Get info about specified campaigns
     * */
    public function getCampaignsInfo($campaigns)
    {
        if (!$campaigns) {
            $campaigns = $this->list();
        }

        $sql = '';
        foreach($campaigns as $idx => $camp) {
            $union = $idx ? 'union' : '';
            $sql .= "$union select '$camp' as campaign,
                     (select coalesce(count(*),0) from a2i_campaign_$camp where s_type='number') as numbers_total,
                     (select coalesce(count(*),0) from a2i_campaign_$camp where s_type='number' and s_def::json->>'x-finished' = 'true') as numbers_finished,
                     (select coalesce(sum((s_def::json->>'x-tries-total')::int),0) from a2i_campaign_$camp where s_type='number') as calls_total,
                     (select coalesce(sum((s_def::json->>'x-tries-success')::int),0) from a2i_campaign_$camp where s_type='number') as calls_success,
                     s_def from a2i_campaign_$camp where s_type='status' ";
        }

        //file_put_contents('/tmp/1.txt', $sql);

        $ret = [];
        $rows = $this->db->query($sql);
        foreach ($rows as $row) {
            $campaign = $row['campaign'];
            $numbers_total = $row['numbers_total'];
            $numbers_finished = $row['numbers_finished'];
            $calls_total = $row['calls_total'];
            $calls_success = $row['calls_success'];

            $campStatus = json_decode($row['s_def'], true);

            if (!$campStatus) continue;

            $campStatus['campaign'] = $campaign;
            $campStatus['numbers_total'] = $numbers_total;
            $campStatus['numbers_finished'] = $numbers_finished;
            $campStatus['calls_total'] = $calls_total;
            $campStatus['calls_success'] = $calls_success;

            if ($calls_total) {
                $campStatus['calls_success_percent'] = round($calls_success/$calls_total*100);
            } else {
                $campStatus['calls_success_percent'] = 0;
            }

            if ($numbers_total) {
                $campStatus['campaign_progress_percent'] = round($numbers_finished/$numbers_total*100);
                $campStatus['campaign_success_percent'] = round($calls_success/$numbers_total*100);
            } else {
                $campStatus['campaign_progress_percent'] = 0;
                $campStatus['campaign_success_percent'] = 0;
            }

            $ret[$campaign] = $campStatus;
        }

        return $ret;
    }

    private function checkMandatoryKeys($mandatoryKeys, $keys)
    {
        return array_intersect($mandatoryKeys, $keys) == $mandatoryKeys;
    }

    private function __clone()
    {
    }

    private function __construct()
    {
    }
}
