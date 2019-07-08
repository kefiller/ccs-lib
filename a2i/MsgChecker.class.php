<?php

namespace CCS\a2i;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, PSR2.Classes.PropertyDeclaration.Underscore
class MsgChecker
{
    /** @var array */
    private $_msg;

    public function __construct(array $msg)
    {
        $this->_msg = $msg;
    }

    public function set($msg)
    {
        $this->_msg = $msg;
    }

    // Проверяет попадание текущего даты/времени в разрешенный диапазон
    public function checkWorkTime()
    {
        //    [interval-wtime] => 09:00:00-18:00:00
        //    [interval-dow] => mon-fri
        // Проверим наличие нужных параметров
        if (!isset($this->_msg['interval-wtime'])  || $this->_msg['interval-wtime'] == ''
            || !isset($this->_msg['interval-dow']) || $this->_msg['interval-dow'] == '') {
            return false;
        }

        // Дни недели и их номера
        $dowMap = ['stub', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

        // Проверим попадание в разрешенные дни

        if ($this->_msg['interval-dow'] != '*') {
            $dDOWtoday = date("N"); // сегодняшний день(№), например 3

            $aDOWPieces = explode('-', $this->_msg['interval-dow']);
            if (count($aDOWPieces) != 2) {
                return false;
            } // не распарсилось

            $sDOWStart = $aDOWPieces[0]; // mon
            $sDOWStop  = $aDOWPieces[1]; // fri

            $dDOWStart = array_search($sDOWStart, $dowMap); // 1
            $dDOWStop  = array_search($sDOWStop, $dowMap); // 5

            if (!$dDOWStart || !$dDOWStop) {
                return false;
            }

            if (!($dDOWStart <= $dDOWtoday && $dDOWtoday <= $dDOWStop)) {
                return false;
            }
        }

        if ($this->_msg['interval-wtime'] != '*') {
            $aTmIntPieces = explode('-', $this->_msg['interval-wtime']);
            if (count($aTmIntPieces) != 2) {
                return false;
            } // не распарсилось

            //$dateTimeFmt = "Y-m-d H:i:s.u";
            $dateFmt = "Y-m-d";

            $dtIntvlStart = trim($aTmIntPieces[0]); // начало интервала, например 09:00:00
            $dtIntvlStop  = trim($aTmIntPieces[1]); // конец  интервала, например 18:00:00

            // Преобразуем в полную дату/время, для этого добавим интервалы к текущему дню

            $dtToday = date($dateFmt); // 2017-02-15

            $dtIntvlStart = $dtToday." ".$dtIntvlStart; // 2017-02-15 09:00:00
            $dtIntvlStop  = $dtToday." ".$dtIntvlStop;  // 2017-02-15 18:00:00

            $tmNow   = time();
            $tmStart = strtotime($dtIntvlStart);
            $tmStop  = strtotime($dtIntvlStop);

            if ($tmStart <= $tmNow && $tmNow <= $tmStop) {
                return true;
            }
            return false;
        }

        return true;
    }

    // Проверить, не вышел ли срок действия кампании
    // false - кампания активна
    // true  - у кампании вышел срок действия
    public function checkCampaignExpiry()
    {
        //    expire - 2017-02-20 23:59:59
        // Проверим наличие нужных параметров, если параметр не задан или пуст,
        // считаем, что кампания не имеет срока действия
        if (!isset($this->_msg['expire']) || $this->_msg['expire'] == '') {
            return false;
        }

        $curTm = time(); // current unixtime
        $expTm = strtotime($this->_msg['expire']); // expire date -> unixtime
        return $curTm > $expTm;
    }

    // Проверить, успешно ли мы доставили все необходимые сообщения (x-tries-success == amount)
    // false - не все сообщения успешно доставлены
    // true -  все сообщения успешно доставлены
    public function checkTriesSuccess()
    {
        if (!isset($this->_msg['x-tries-success']) || $this->_msg['x-tries-success'] == '' ||
            !isset($this->_msg['amount']) || $this->_msg['amount'] == ''
        ) {
            return false;
        }
        return $this->_msg['x-tries-success'] == $this->_msg['amount'];
    }

    // Не превысили ли мы лимиты на отправку сообщений(общее кол-во попыток)
    // false - лимит не достигнут
    // true  - лимит достигнут
    public function checkTriesTotal()
    {
        if (!isset($this->_msg['x-tries-total']) || $this->_msg['x-tries-total'] === '' ||
            !isset($this->_msg['retry']) || $this->_msg['retry'] === ''
        ) {
            return true;
        }
        //    out("this->_msg['x-tries-total']=".$this->_msg['x-tries-total']."
        // this->_msg['retry']=".$this->_msg['retry']);
        return $this->_msg['x-tries-total'] >= $this->_msg['retry'];
    }

    // Проверить интервал отправки сообщений(в случае последней успешной и неуспешной доставки)
    // false - интервал отправки не пройден (нельзя доставлять сообщения)
    // true  - интервал отправки пройден (можно доставлять собщения)
    public function checkSendInterval()
    {
        if (!isset($this->_msg['x-send-status']) ||
            !isset($this->_msg['x-send-date'])   || $this->_msg['x-send-date'] === '' ||
            !isset($this->_msg['retry-secs'])    || $this->_msg['retry-secs'] === '' ||
            !isset($this->_msg['interval-send']) || $this->_msg['interval-send'] === ''
        ) {
            return false;
        }

        $dtFormat = "Y-m-d H:i:s";

        // Текущие дата и время
        $dtNow = date($dtFormat);

        // Unixtime
        $tmNow = strtotime($dtNow);

        // Время последней отправки
        $tmLastSend = strtotime($this->_msg['x-send-date']);

        // Интервал между последней отправкой
        $tmDiff = $tmNow - $tmLastSend;

        //    out("checkSendInterval: ".$this->_msg['number']."
        // now = '$dtNow' lastSendDate='".$this->_msg['x-send-date']."', diff=$tmDiff");

        if ($this->_msg['x-send-status'] == 'error' || $this->_msg['x-send-status'] === '') {
            //        out("error or empty: this->_msg['retry-secs']".$this->_msg['retry-secs']." tmDiff=$tmDiff");
            return $tmDiff >= $this->_msg['retry-secs'];
        } elseif ($this->_msg['x-send-status'] == 'success') {
            //        out("sucess: this->_msg['interval-send']".$this->_msg['interval-send']." tmDiff=$tmDiff");
            return $tmDiff >= $this->_msg['interval-send'];
        }
        //out("here");
        return false;
    }
}
