<?php

namespace CCS\a2i;

use CCS\Result;
use CCS\IApiClient;
use CCS\ResultError;
use CCS\db\MyDB;
use CCS\Logger;
use CCS\pbx\OriginateTarget;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, PSR2.Classes.PropertyDeclaration.Underscore
class Call
{
    /** @var Mydb|null */
    private $_db = null;
    /** @var string */
    private $_table = '';
    private $_s_type = 'number';
    /** @var string */
    private $_number = '';
    /** @var array */
    private $_campSettings = [];

    /** @var array */
    private $_data = [];
    /** @var array */
    private $_aggrData = [];

    private $_dtFormat = 'Y-m-d H:i:s';
    private $_zTimeStamp = '1970-01-01 03:00:00'; // zero timestamp - unixtime = 0

    private $_lastError = '';

    /** @var int */
    private $xSendTriesMax;

    /** @var int */
    private $xSendTriesIntvl;

    /**
     * Api client
     * @var IApiClient
     */
    private $apiClient;

    public function __construct(
        $db,
        $table,
        $sNumber,
        $campSettings,
        $xSendTriesMax,
        $xSendTriesIntvl,
        IApiClient $apiClient
    ) {
        $this->_db = $db;
        $this->_table = $table;
        $this->_number = $sNumber;
        $this->_campSettings = $campSettings;

        $this->xSendTriesMax = $xSendTriesMax;
        $this->xSendTriesIntvl = $xSendTriesIntvl;

        $this->apiClient = $apiClient;
    }

    public function getData()
    {
        return $this->_data;
    }

    public function getCampaign()
    {
        return str_replace('a2i_campaign_', '', $this->_table);
    }

    public function deserialize()
    {
        if (!$this->_db || !$this->_table || !$this->_number || !$this->_campSettings) {
            return false;
        }

        $this->_data = $this->_db->deserializeEntity($this->_table, $this->_s_type, $this->_number);
        if (count($this->_data) == 0 || !isset($this->_data[$this->_number])) {
            return false;
        }

        $this->_data = $this->_data[$this->_number];

        // для номера не определены доп. атрибуты. Значит, номер новый, надо добавить
        if (!isset($this->_data['x-tries-total'])) {
            $this->_data['x-tries-total'] = 0;                  // общее кол-во попыток отправки звонка
            $this->_data['x-tries-success'] = 0;                // кол-во успешных попыток
            $this->_data['x-tries-error'] = 0;                  // кол-во неудачных попыток
            $this->_data['x-send-date'] = $this->_zTimeStamp;   // дата последней попытки отправки звонка
            $this->_data['x-send-status'] = "";                 // статус последней отправки звонка
            $this->_data['x-msg-id'] = "";                      // ActionID звонка из последней отправки
            // кол-во попыток отправки звонка(в систему доставки). Если больше
            // X_SEND_TRIES_MAX, увеличивается кол-во (+неудачных) попыток отправки,
            // статус последней отправки выставляется в ошибочный.
            $this->_data['x-send-tries'] = 0;
            $this->_data['x-send-try-date'] = $this->_zTimeStamp; // дата последней попытки отправки звонка
            $this->_data['x-finished'] = 'false';               // флаг завершения обработки номера
            // флаг признака того, что по номеру в настоящий момент совершается звонок
            $this->_data['x-in-call'] = 'false';
            // по звонку получен OriginateResponse (call-tracking)
            $this->_data['x-originate-responded'] = false;
        }

        $this->buildAggregatedSettings();

        // Подставим переменные в шаблон сообщения
        if (isset($this->_aggrData['msg-template'])) {
            $msg = $this->substTemplate($this->_aggrData['msg-template'], $this->_aggrData);
            $this->_data['message'] = $msg;
            $this->_aggrData['message'] = $msg;
        }

        return true;
    }

    public function serialize()
    {
        $this->_db->serializeEntity($this->_table, $this->_s_type, [$this->_number => $this->_data]);
    }

    public function getAggrData()
    {
        $this->buildAggregatedSettings();
        return $this->_aggrData;
    }

    public function getMessage()
    {
        return isset($this->_aggrData['message'])?$this->_aggrData['message']:'';
    }

    public function getRecord()
    {
        return isset($this->_aggrData['record'])?$this->_aggrData['record']:'';
    }

    public function getSendStatus()
    {
        return $this->_data['x-send-status'];
    }

    public function getStatusCheckTries()
    {
        return $this->_data['x-send-status-check-tries'];
    }

    public function getSendTries()
    {
        return $this->_data['x-send-tries'];
    }

    public function getDiff($data)
    {
        $this->buildAggregatedSettings();

        // array_diff_assoc cannot handle multidimensional arrays,
        // when used with such arrays, it's annoyng (very 'clear') warning
        // 'Array to string conversion in ...'
        // so we just ignore arrays in $data/$this->_aggrData
        // Anyway it contains static data (I hope), that isnt changing

        $arr1 = [];
        $arr2 = [];

        foreach ($data as $k => $v) {
            if (!is_array($v)) {
                $arr1[$k] = $v;
            }
        }

        foreach ($this->_aggrData as $k => $v) {
            if (!is_array($v)) {
                $arr2[$k] = $v;
            }
        }

        return array_diff_assoc($arr2, $arr1);
    }

    public function finish()
    {
        $this->_data['x-finished'] = 'true';
    }

    public function send()
    {
        if ($this->_data['x-send-tries'] < $this->xSendTriesMax) {
            // сколько прошло времени с момента последней попытки отправки

            // Текущие дата и время
            $dtNow = date($this->_dtFormat);

            // Unixtime
            $tmNow = strtotime($dtNow);

            // Время последней отправки
            $tmLastSend = strtotime($this->_data['x-send-try-date']);

            // Интервал между последней отправкой
            $tmDiff = $tmNow - $tmLastSend;

            if ($tmDiff < $this->xSendTriesIntvl) {
                // прошло меньше времени, чем заданный интервал отправки
                $this->setLastError("send interval not finished");
                return false;
            }

            //обновим время последней попытки отправки
            $this->_data['x-send-try-date'] = $dtNow;

            $origNum = $this->_data['number'];
            $origTrunk = $this->_aggrData['trunk'];
            $origClid = $this->_aggrData['callerid'];
            $origCardID = $this->_aggrData['cardid'] ?? '';
            $pbxQueue = $this->_aggrData['queue'] ?? '';

            $origNumSuff = "";
            if ($origCardID) {
                $origNumSuff = "^$origClid^$origCardID";
            }

            $origDest = [ 'type' => 'number', 'number' => "$origNum{$origNumSuff}", 'callerid' => $origClid,
            'trunk' => $origTrunk,];
            $origBridgeTarget = [ 'type' => 'dialplan', 'context' => 'a2i-connect',
             'extension' => "$origNum"];

            // Fill pbx variables
            $extraData = ['PBX-CAMPAIGN' => $this->getCampaign()];
            $extraData['PBX-NUM'] = $origNum;

            $ttsRec = $this->getTTS();
            if ($ttsRec) {
                $extraData['PBX-TTSREC'] = $ttsRec;
            }

            if ($pbxQueue) {
                $extraData['PBX-QUEUE'] = $pbxQueue;
            }

           // If bridge-target exists
            if ($this->hasBridgeTarget()) {
                $bridgeTarget = $this->getBridgeTarget();
                if ($bridgeTarget->ok()) {
                    $brt = $bridgeTarget->data();
                    $extraData['PBX-BRT-TYPE'] = $brt->getType();
                    if ($brt->getType() == 'number') {
                        $extraData['PBX-BRT-NUM'] = $brt->getChannel();
                        if ($brt->getCallerid()) {
                            $extraData['PBX-BRT-CLID'] = $brt->getCallerid();
                        }
                    } elseif ($brt->getType() == 'dialplan') {
                        $extraData['PBX-BRT-CTX'] = $brt->getContext();
                        $extraData['PBX-BRT-EXTEN'] = $brt->getExtension();
                    }
                } else {
                    Logger::log("Error creating bridge-target for call: " . $bridgeTarget->errorDesc());
                }
            }

            // Пытаемся отправить звонок
            $origResult = $this->apiClient->callOriginate($origDest, $origBridgeTarget, $extraData);

            if ($origResult->error()) {
                $errStr = $origResult->errorDesc();
                Logger::log("Error send call ".$this->_number." to delivery service: ".$errStr);
                $this->_data['x-send-tries']++;
                $this->setLastError($errStr);

                return false;
            }

            $origResultData = $origResult->data() ?? [];
            if (!count($origResultData)) {
                $errStr = "Empty result data";
                Logger::log("Error send call ".$this->_number." to delivery service: ".$errStr);
                $this->_data['x-send-tries']++;
                $this->setLastError($errStr);

                return false;
            }

            $srvCallId = '';
            foreach ($origResultData as $srvResult) {
                $srv = $srvResult['srv'];
                $srvStatus = $srvResult['result'];
                $srvCallId = $srvResult['call-id'];
                $num = $this->_number;

                Logger::log("Call to $num queued on $srv with status '$srvStatus' and id '$srvCallId'");
            }
            $this->_data['x-msg-id'] = $srvCallId;
            $this->_data['x-send-status'] = 'queued';
            $this->setInCall(true);
        } else {
            $this->_data['x-send-tries'] = 0;
            $this->_data['x-tries-error']++;
            $this->_data['x-send-status'] = 'error';
            $this->setInCall(false);
        }
        // Обновим время последней отправки
        $this->_data['x-send-date'] = date($this->_dtFormat);
        $this->_data['x-tries-total']++; // Увеличиваем счетчик отправок
        $this->_data['x-send-try-date'] = $this->_zTimeStamp; // обнулим время последней попытки отправки

        $evtKeys = $this->_data;
        $evtKeys['campaign'] = $this->getCampaign();
        $this->apiClient->eventsEmit($evtKeys, 'a2i.originate.init');
        return true;
    }

    public function getLastError()
    {
        return $this->_lastError;
    }

    public function setCallSuccess()
    {
        $this->_data['x-tries-success']++;
        $this->_data['x-send-tries'] = 0;
        $this->_data['x-send-status'] = 'success';
        $this->_data['x-send-try-date'] = $this->_zTimeStamp;
    }

    public function setCallError()
    {
        $this->_data['x-tries-error']++;
        $this->_data['x-send-tries'] = 0;
        $this->_data['x-send-status'] = 'error';
        $this->_data['x-send-try-date'] = $this->_zTimeStamp;
    }

    public function setInCall($inCall)
    {
        if ($inCall) {
            $this->_data['x-in-call'] = 'true';
        } else {
            $this->_data['x-in-call'] = 'false';
            $this->_data['x-send-status'] = '';
        }
    }

    public function inCall()
    {
        return $this->_data['x-in-call'] == 'true';
    }

    public function setBillsec($billsec)
    {
        $this->_data['x-billsec'] = $billsec;
    }

    public function setTTS(string $tts)
    {
        $this->_data['x-tts-rec'] = $tts;
    }

    public function getTTS()
    {
        if (isset($this->_data['x-tts-rec'])) {
            return $this->_data['x-tts-rec'];
        }
        return '';
    }

    public function setCallID(string $callid)
    {
        $this->_data['x-callid'] = $callid;
    }

    /** @return string */
    public function getCallID()
    {
        if (isset($this->_data['x-callid'])) {
            return $this->_data['x-callid'];
        }
        return '';
    }

    public function setCallRec(string $callRec)
    {
        $this->_data['x-callrec'] = $callRec;
    }

    /** @return string */
    public function getCallRec()
    {
        if (isset($this->_data['x-callrec'])) {
            return $this->_data['x-callrec'];
        }
        return '';
    }

    /** @return boolean */
    public function hasBridgeTarget()
    {
        $brtField = $this->getField('bridge-target');
        return $brtField !== '';
    }

    /** @return Result */
    public function getBridgeTarget()
    {
        if (!$this->hasBridgeTarget()) {
            return new ResultError("'bridge-target' not set for number or campaign");
        }

        $brtField = $this->getField('bridge-target');
        $brt = OriginateTarget::create($brtField);
        if ($brt->error()) {
            return new ResultError("Error validating OriginateTarget : " . $brt->errorDesc());
        }
        return $brt;
    }

    public function getField(string $field)
    {
        $aggrData = $this->getAggrData();
        if (isset($aggrData[$field])) {
            return $aggrData[$field];
        }
        return '';
    }

    public function getId()
    {
        return $this->_data['x-msg-id'];
    }

    public function isOriginateResponded() {
        return ($this->_data['x-originate-responded'] ?? false);
    }

    public function setOriginateResponded($originateResponded) {
        $this->_data['x-originate-responded'] = $originateResponded;
    }

    private function setLastError($str)
    {
        $this->_lastError = $str;
    }

    // Получить аггрегированные настройки для номера(персональные настройки номера + настройки кампании(+ ...))
    private function buildAggregatedSettings()
    {
        $result = $this->_campSettings;

        // Настройки номера (переопределяют общие настройки кампании)
        // не используем array_merge, т.к. поля должны переопределяться
        foreach ($this->_data as $key => $val) {
            //if (!isset($result[$key]) || $val != '') {
            if ($val === '__CAMPAIGN__') {
                continue;
            }
            $result[$key] = $val;
        }
        $this->_aggrData = $result;
    }

    // Подставляет в строку $str значение параметров ${param1}, ${param2}, ... из массива $params
    private function substTemplate(string $str, array $params)
    {
        foreach ($params as $k => $v) {
            $param = '${'.$k.'}';
            $value = $v;
            if (strpos($str, $param) !== false) { // найден параметр
                $str = str_replace($param, $value, $str); // подставить значение
            }
        }
        return $str;
    }
}
