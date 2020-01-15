<?php

namespace CCS\a2i;

use CCS\Logger;
use CCS\HttpApiClient;
use CCS\WebsocketApiClient;
use CCS\TTSYaCloud;
use CCS\util\_;
use CCS\db\MyDB;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, PSR2.Classes.PropertyDeclaration.Underscore
class Calld
{
    /** @var string */
    private $_campaign;
    /** @var string */
    private $_campSettType = 'settings';
    /** @var array */
    private $_campSettings;

    /** @var string */
    private $defaultTTSGenMode = 'on-call';

    /** @var MyDB */
    private $_db;
    /** @var DBLogger */
    private $_dbLogger;

    private $_stopServer = false;

    /** @var string */
    private $_stopReason;

    private $_dtFormat = 'Y-m-d H:i:s';

    private $_status = [];

    private $_bNotWorkTimeMsgShown = [];
    private $_bNoFreeChannelsMsgShown = false;

    /** @var array */
    private $amiServers;

    /**
     * trunk location name
     * @var string */
    private $trunkLocTbl;

    /**
     * Application config
     *  @var array
     * */
    private $config;

    /**
     * Websocket Api client
     * @var IApiClient
     */
    private $wsApiClient;

    /**
     * HTTP Api client
     * @var IApiClient
     */
    private $httpApiClient;

    private $loopTimerId;

    public function __construct(
        string $campaign,
        MyDB &$db,
        DBLogger $dbLogger,
        array $config,
        WebsocketApiClient $wsApiClient,
        HttpApiClient $httpApiClient
    ) {
        $this->_campaign = $campaign;
        $this->_db = $db;
        $this->_dbLogger = $dbLogger;

        $this->config = $config;
        $this->wsApiClient = $wsApiClient;
        $this->httpApiClient = $httpApiClient;

        $this->_status['status']   = 'running';
        $this->_status['hostname'] = gethostname();
        $this->_status['pid']      = getmypid();
        $this->_status['type']     = 'call';
    }

    public function run()
    {
        Logger::log("Daemon started");

        $this->wsApiClient->onEvent(function ($event) {
            $this->evtListener($event);
        });

        $this->loopTimerId = \swoole_timer_tick(500, function () {
            $this->loop();
        });

        $evtKeys = [];
        $evtKeys['campaign'] = $this->_campaign;
        $this->httpApiClient->eventsEmit($evtKeys, 'a2i.campaign.started');
    }

    private function evtListener($event)
    {
        //Logger::log("event: " . print_r($event, true));

        $evtName = $event['event'];
        $evtFields = $event['data'];

        if ($evtName == 'a2i.connect.complete') {
            $campaign  = $evtFields['pbx-campaign'];
            $number    = $evtFields['num'];
            $heard     = $evtFields['heard'];
            $billsec   = $evtFields['billsec'];
            $callid    = $evtFields['uniqueid'];
            $callRec   = $evtFields['callrec'];
            $apiCallId = $evtFields['api-call-id'];
            //Logger::log("campaign=$campaign number=$number heard=$heard");
            $campaign = $this->fixCampName($campaign);

            if ($campaign != $this->_campaign) {
                return;
            }

            $call = new Call(
                $this->_db,
                $this->_campaign,
                $number,
                $this->_campSettings,
                $this->config['X_SEND_TRIES_MAX'],
                $this->config['X_SEND_TRIES_INTVL'],
                $this->httpApiClient
            );
            if (!$call->deserialize()) {
                Logger::log("Could not deserialize data for $number");
                return; // не получилось десериализовать
            }

            // Сохраним для лога
            $origCall = $call->getAggrData();

            $hearSecs = false; // кол-во секунд, если прослушано более чем, звонок считается успешным
            if (isset($origCall['hear-secs']) && is_numeric($origCall['hear-secs'])) {
                $hearSecs = $origCall['hear-secs'];
            }

            $call->setBillsec($billsec);
            //@phan-suppress-next-line PhanTypeSuspiciousStringExpression
            Logger::log("$number: heard=$heard billsec=$billsec hearSecs=$hearSecs callid:$callid"
            ." callRec:$callRec id '$apiCallId'");

            // Надо ли определять продолжительность прослушивания
            if ($hearSecs === false) { // не надо, считаем звонок успешно завершенным
                $call->setCallSuccess();
            } elseif ($hearSecs == 0) { // должен быть прослушан весь текст
                if ($heard == '1') {
                    $call->setCallSuccess();
                } else {
                    $call->setCallError();
                }
            } else { // должно быть прослушано не менее hearSecs секунд
                if ($heard == '1' || $billsec >= $hearSecs) {
                    $call->setCallSuccess();
                } else {
                    $call->setCallError();
                }
            }
            $call->setCallID($callid); // call uniqueid
            $call->setCallRec($callRec); // path to call record

            $call->serialize();
            $diff = $call->getDiff($origCall);
            if (count($diff)) {
                $extraFields = $this->getLogExtraFields($call);
                $this->_dbLogger->log($campaign, $number, ['type' => 'change', 'diff' => $diff, 'extra-fields' =>
                 $extraFields]);
            }

            $evtKeys = $call->getData();
            $evtKeys['campaign'] = $call->getCampaign();
            $this->httpApiClient->eventsEmit($evtKeys, 'a2i.originate.result');

            return;
        }

        if ($evtName == 'originate.response') {
            $number    = $evtFields['extra-data']['PBX-NUM'] ?? '';
            $campaign  = $evtFields['extra-data']['PBX-CAMPAIGN'] ?? '';
            $apiCallId = $evtFields['id'];

            $response = $evtFields['response'];
            $reason = $evtFields['reason'];
            $reasonDesc = $evtFields['reason-desc'];

            $campaign = $this->fixCampName($campaign);

            if ($campaign != $this->_campaign) {
                return;
            }

            if (!$number) {
                Logger::log("Empty number in campaign $campaign api-call-id $apiCallId");
                return;
            }

            $call = new Call(
                $this->_db,
                $this->_campaign,
                $number,
                $this->_campSettings,
                $this->config['X_SEND_TRIES_MAX'],
                $this->config['X_SEND_TRIES_INTVL'],
                $this->httpApiClient
            );
            if (!$call->deserialize()) {
                Logger::log("Could not deserialize data for $number");
                return; // не получилось десериализовать
            }

            // Сохраним для лога
            $origCall = $call->getAggrData();

            // Получили OriginateResponse (call tracking)
            $call->setOriginateResponded(true);

            // Отменим запланированный call-tracking, получили OriginateResponse
            $timerId = $call->getTimerId();
            if ($timerId) {
                \swoole_timer_clear($timerId);
            }

            Logger::log("$number: $response $reason $reasonDesc id '$apiCallId' uniqueid:" . ($evtFields['uniqueid'] ?? ''));

            if ($response == "Failure") { // Call was not answered
                $call->setCallError();
                $call->setInCall(false);
            }

            $call->serialize();
            $diff = $call->getDiff($origCall);
            if (count($diff)) {
                $extraFields = $this->getLogExtraFields($call);
                $this->_dbLogger->log($campaign, $number, ['type' => 'change', 'diff' => $diff, 'extra-fields' =>
                 $extraFields]);
            }
            $evtKeys = $call->getData();
            $evtKeys['campaign'] = $call->getCampaign();
            $evtKeys['status'] = $response;
            $evtKeys['reason'] = $reason;
            $evtKeys['reason-desc'] = $reasonDesc;

            $this->httpApiClient->eventsEmit($evtKeys, 'a2i.originate.response');

            return;
        }

        if ($evtName == 'originate.complete') {
            $number    = $evtFields['extra-data']['PBX-NUM'] ??  '';
            $campaign  = $evtFields['extra-data']['PBX-CAMPAIGN'] ??  '';
            $apiCallId = $evtFields['id'];

            $campaign = $this->fixCampName($campaign);

            if ($campaign !=  $this->_campaign) {
                return;
            }

            Logger::log("originate.complete campaign:$campaign uniqueid:" . ($evtFields['uniqueid'] ?? ''));

            if (!$number) {
                Logger::log("Empty number in campaign $campaign api-call-id $apiCallId");
                return;
            }

            $call = new Call(
                $this->_db,
                $this->_campaign,
                $number,
                $this->_campSettings,
                $this->config['X_SEND_TRIES_MAX'],
                $this->config['X_SEND_TRIES_INTVL'],
                $this->httpApiClient
            );
            if (!$call->deserialize()) {
                Logger::log("Could not deserialize data for $number");
                return; // не получилось десериализовать
            }

            Logger::log("$number: $evtName id '$apiCallId'");
            $call->setInCall(false) ;
            $call->serialize() ;

            return;
        }

        // events, related to queue workflow
        $qRelEvents = [
            'queue.join',
            'queue.leave',
            'queue.abandon',
            'operator.call.init',
            'operator.call.response',
            'operator.call.complete',
        ];

        if (in_array($evtName, $qRelEvents)) {
            $number    = $evtFields['extra-data']['PBX-NUM'] ?? '';
            $campaign  = $evtFields['extra-data']['PBX-CAMPAIGN'] ?? '';
            $apiCallId = $evtFields['id'] ?? '';

            $campaign = $this->fixCampName($campaign);
            if ($campaign != $this->_campaign) {
                return;
            }

            if (!$number) {
                Logger::log("$evtName: Empty number in campaign $campaign api-call-id $apiCallId");
                return;
            }

            $call = new Call(
                $this->_db,
                $this->_campaign,
                $number,
                $this->_campSettings,
                $this->config['X_SEND_TRIES_MAX'],
                $this->config['X_SEND_TRIES_INTVL'],
                $this->httpApiClient
            );
            if (!$call->deserialize()) {
                Logger::log("$evtName: could not deserialize data for $number");
                return; // не получилось десериализовать
            }

            Logger::log("$evtName: $number id '$apiCallId'");

            $evtKeys = $call->getData();
            $evtKeys['campaign'] = $call->getCampaign();

            foreach ($evtFields as $k => $v) {
                if (!is_array($v)) {
                    $evtKeys[$k] = $v;
                }
            }

            $this->httpApiClient->eventsEmit($evtKeys, "a2i.$evtName");

            return;
        }

        return;
    }

    private function loop()
    {
        $this->_campSettings = []; // Настройки кампании

        // Читаем настройки из таблицы кампании:
        $aCampSettings = $this->_db->deserializeEntity($this->_campaign, $this->_campSettType);

        // Должен быть устаовлен один из атрибутов: msg-template или record
        $bMsgTplSet = false;
        $bRecordSet = false;

        $mandFields = ['trunk','channels','callerid','interval-wtime','interval-dow','amount',
        'retry','retry-secs','interval-send'];
        $setts = $aCampSettings[$this->_campSettType];

        // Проверим наличие обязательных полей
        // Если нет, или некорректные, или нет активных завершаем работу кампании
        if (!_::hasMandatoryKeys($mandFields, $setts)) {
            $this->stop("No valid settings for campaign, check mandatory fields: ".implode(',', $mandFields));
        }

        if ((isset($setts['msg-template']) && $setts['msg-template'] != '')) {
            $bMsgTplSet = true;
        }

        if ((isset($setts['record']) && $setts['record'] != '')) {
            $bRecordSet = true;
        }
        if (!($bMsgTplSet xor $bRecordSet)) {
            $this->stop("msg-template OR record should be set");
        }

        $this->_campSettings = $setts;

        // Check TTS
        if (!isset($this->_campSettings['tts-generate'])) {
            $this->_campSettings['tts-generate'] = $this->defaultTTSGenMode;
        }

        $sNumber = '';

        // handle pending signals
        pcntl_signal_dispatch();

        if ($this->_stopServer) {
            $this->quit();
        }

        // Есть ли номера, работа с которыми еще не завершена
        $sql = "select s_name from ".$this->_campaign." where s_type = 'number'
            and case when (s_def::json->>'x-finished') is not NULL then s_def::json->>'x-finished' <> 'true'
            else true end";

        $rows = $this->_db->query($sql);

        // Если таких номеров нет, завершаем кампанию (или можно выполнить действие по настройкам кампании)
        if (count($rows) == 0) {
            $this->stop("No numbers left for processing");
            return;
        }

        $checker = new MsgChecker($this->_campSettings);

        // Проверить, не истек ли срок действия кампании
        if ($checker->checkCampaignExpiry()) {
            $this->stop("Campaign has expired");
            return;
        }

        // По каждому номеру:
        foreach ($rows as $row) {
            // Определим, есть ли свободные каналы для совершения звонков
            $sql = "select count(*) from ".$this->_campaign." where s_type = 'number'
             and s_def::json->>'x-in-call' = 'true'";
            $rows = $this->_db->query($sql);

            if ($rows[0]['count'] >= $this->_campSettings['channels']) {
                if (!$this->_bNoFreeChannelsMsgShown) {
                    Logger::log("All available channels occupied");
                    $this->_bNoFreeChannelsMsgShown = true;
                }
                $this->updateStatus($sNumber);
                return;
            } else {
                $this->_bNoFreeChannelsMsgShown = false;
            }

            // Вытащим очередной номер
            if (!isset($row['s_name']) || $row['s_name'] == '') {
                continue;
            }
            $sNumber = $row['s_name'];

            $call = new Call(
                $this->_db,
                $this->_campaign,
                $sNumber,
                $this->_campSettings,
                $this->config['X_SEND_TRIES_MAX'],
                $this->config['X_SEND_TRIES_INTVL'],
                $this->httpApiClient
            );
            if (!$call->deserialize()) {
                Logger::log("Could not deserialize data for $sNumber");
                continue; // не получилось десериализовать
            }

            // Если в разговоре
            if ($call->inCall()) {
                //Logger::log("$sNumber still in call");
                // TODO Потом надо запросить данные у callMaker'а, действительно ли там торчит этот звонок...
                continue;
            }

            // Сохраним для лога
            $origCall = $call->getAggrData();

            // place to generate TTS
            $ttsGenMode = $this->_campSettings['tts-generate'];
            if ($ttsGenMode == 'on-call') { // (for now) do not know, how to handle other types...
                // extract TTS settings from campaign settings to $ttsConfig
                $ttsConfig = [];
                foreach ($this->_campSettings as $k => $v) {
                    if (substr($k, 0, 4) == 'tts-') { // TTS related
                        $ttsKey = substr($k, 4);
                        $ttsConfig[$ttsKey] = $v;
                    }
                }
                // Create and generate message with given config
                try {
                    $tts = new TTSYaCloud($call->getMessage(), $this->config['tts'], $ttsConfig);
                } catch (\Exception $e) {
                    Logger::log("Exception generating TTS for $sNumber:" . $e->getMessage());
                    continue;
                }
                $rslt = $tts->get();
                if ($rslt->error()) {
                    Logger::log("Could not generate TTS for $sNumber:" . $rslt->errorDesc());
                    continue;
                }
                $call->setTTS($tts->getRecTailName());
                //Logger::log("Generated TTS for $sNumber:" . $rslt->data());
            }

            $checker->set($call->getAggrData());

            // Проверить, успешно ли мы доставили все необходимые сообщения
            if ($checker->checkTriesSuccess()) { // пометим номер как завершенный
                $call->finish();
                $call->serialize();
                $diff = $call->getDiff($origCall);
                if (count($diff)) {
                    $extraFields = $this->getLogExtraFields($call);
                    $this->_dbLogger->log($this->_campaign, $sNumber, ['type' => 'change', 'diff' => $diff,
                     'extra-fields' => $extraFields]);
                }
                Logger::log("$sNumber : marked as finished(success)");
                $this->updateStatus($sNumber);
                continue;
            }

            // Не превысили ли мы лимиты на отправку ообщений(общее кол-во попыток)
            if ($checker->checkTriesTotal()) {
                // пометим номер как завершенный
                $call->finish();
                $call->serialize();
                $diff = $call->getDiff($origCall);
                if (count($diff)) {
                    $extraFields = $this->getLogExtraFields($call);
                    $this->_dbLogger->log($this->_campaign, $sNumber, ['type' => 'change', 'diff' => $diff,
                     'extra-fields' => $extraFields]);
                }
                Logger::log("$sNumber : marked as finished(all tries left)");
                $this->updateStatus($sNumber);
                continue;
            }

            // Что-то еще надо делать...

            // Проверить возможность совершения действий (интервал рабочего времени кампании)
            if (!$checker->checkWorkTime()) {
                $numNotWorkTimeMsgShown = $this->_bNotWorkTimeMsgShown[$sNumber] ?? false;
                if (!$numNotWorkTimeMsgShown) {
                    Logger::log("$sNumber : not work time");
                    $this->_bNotWorkTimeMsgShown[$sNumber] = true;
                }
                $this->updateStatus($sNumber);
                continue;
            } else {
                $this->_bNotWorkTimeMsgShown[$sNumber] = false;
            }

            // Проверить интервал отправки звонка (в случае последней успешной и неуспешной доставки)
            if (!$checker->checkSendInterval()) {
                //Logger::log("$sNumber : time interval not reached");
                $this->updateStatus($sNumber);
                continue;
            }

            // Пытаемся отправить звонок
            Logger::log("$sNumber : calling to provider");
            if (!$call->send()) {
                if ($call->getLastError() != 'send interval not finished') {
                    Logger::log("$sNumber : couldn't send call to delivery service, try ".$call->getSendTries()
                    .", reason: '".$call->getLastError()."',  will retry later");
                }
            } else {
                //if ($call->getSendStatus() != '') {
                //    Logger::log("$sNumber(sent to delivery service): callStatus -> ".$call->getSendStatus());
                //}
                $diff = $call->getDiff($origCall);
                if (count($diff)) {
                    $extraFields = $this->getLogExtraFields($call);
                    $this->_dbLogger->log($this->_campaign, $sNumber, ['type' => 'change', 'diff' => $diff,
                     'extra-fields' => $extraFields]);
                }
                $callId = $call->getId();
                $timerId = \swoole_timer_after(60*1000 /*to milliseconds*/, function() use ($sNumber, $callId) {
                    $call = new Call(
                        $this->_db,
                        $this->_campaign,
                        $sNumber,
                        $this->_campSettings,
                        $this->config['X_SEND_TRIES_MAX'],
                        $this->config['X_SEND_TRIES_INTVL'],
                        $this->httpApiClient
                    );
                    if (!$call->deserialize()) {
                        Logger::log("CallTracker: Could not deserialize data for $sNumber");
                        return; // не получилось десериализовать
                    }
                    if ($call->getId() != $callId ) {
                        Logger::log("CallTracker: $sNumber have another callId, skip");
                        return;
                    }
                    if (!$call->inCall()) {
                        Logger::log("CallTracker: $sNumber is not in call, looks good");
                        return;
                    }

                    if ($call->isOriginateResponded()) {
                        Logger::log("CallTracker: $sNumber isOriginateResponded, ok");
                    }
                    Logger::log("CallTracker: $sNumber in BAD state, fix");
                    $call->setCallError();
                    $call->setInCall(false);
                    $call->serialize();
                });
                $call->setTimerId($timerId);
            }

            $call->serialize();
            $this->updateStatus($sNumber);
        }
        usleep(200);
    }

    public function stop($sReason)
    {
        $this->_stopServer = true;
        $this->_stopReason = $sReason;

        $this->_status['status'] = 'stopped';
        $this->_status['pid'] = '';
        $this->_status['reason'] = $sReason; // if not SIGQUIT, watchdog will restart process

        $evtKeys = [];
        $evtKeys['campaign'] = $this->_campaign;
        $evtKeys['reason'] = $sReason;
        $this->httpApiClient->eventsEmit($evtKeys, 'a2i.campaign.stopped');
    }

    private function notifyEmail($msg)
    {
        if (!isset($this->_campSettings['emails']) || $this->_campSettings['emails'] == '') {
            return false;
        }

        $mailFrom = $this->config['EMAIL_FROM'];
        $mailHost  = $this->config['EMAIL_HOST'];
        $mailPort  = $this->config['EMAIL_PORT'];

        $emails = explode(',', $this->_campSettings['emails']);
        foreach ($emails as $email) {
            $fEmail = filter_var($email, FILTER_VALIDATE_EMAIL);
            if ($fEmail === false) {
                continue;
            } // empty/incorrect address

            _::email($mailFrom, $fEmail, $msg, $msg, $mailHost, $mailPort);
        }
    }

    private function updateStatus($lastNumber = '')
    {
        $aPrevStatus = $this->_db->deserializeEntity($this->_campaign, 'status');
        $sPrevStatus = isset($aPrevStatus['status']['status'])?$aPrevStatus['status']['status']:'';

        if ($sPrevStatus == 'created' && $this->_status['status'] == 'running') {
            // Campaign just started
            $this->notifyEmail($this->_campaign." started ");
        }
        $this->_status['lastStatusUpdate'] = date($this->_dtFormat);
        if ($lastNumber != '') {
            $this->_status['lastNumber'] = $lastNumber;
        }
        $this->_db->serializeEntity($this->_campaign, 'status', ['status' => $this->_status]);
    }

    private function quit()
    {
//        if ($this->_stopReason != "SIGTERM") {
//        }
        $this->notifyEmail($this->_campaign." finished: ".$this->_stopReason);
        $this->updateStatus('');
        $this->_dbLogger->log($this->_campaign, '', ['type' => 'stop', 'reason' => $this->_stopReason]);
        Logger::log("Daemon stopped: ".$this->_stopReason);

        // Instead of exit(0), ending all of swoole coroutines
        //@phan-suppress-next-line PhanUndeclaredFunction
        \swoole_timer_clear($this->loopTimerId);
        $this->wsApiClient->disconnect();
    }

    /**
     * Returns array of extra fields, that should be appended to log entry
     * @return array
     */
    private function getLogExtraFields(Call $call)
    {
        $logExtraFields = [];
        if (isset($this->_campSettings['log-extra-fields'])) {
            $logExtraFields = $this->_campSettings['log-extra-fields'];
        }
        if (empty($logExtraFields)) {
            return [];
        }
        $extraFields = [];
        foreach ($logExtraFields as $field) {
            $extraFields[$field] = $call->getField($field);
        }
        return $extraFields;
    }

    /** convert campaign name from 'test' to 'a2i_campaign_test' */
    private function fixCampName($campaign)
    {
        // quick & dirty...
        $campaign = str_replace('a2i_campaign_', '', $campaign);
        return strtolower("a2i_campaign_$campaign");
    }
}
