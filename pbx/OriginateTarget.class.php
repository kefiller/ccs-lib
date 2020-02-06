<?php

namespace CCS\pbx;

use CCS\ResultError;
use CCS\ResultOK;
use CCS\Result;

class OriginateTarget
{
    private $type = '';

    private $data;

    private $number = '';
    private $trunk = '';
    private $callerid = '0000';
    private $timeout = 40;
    private $context = '';
    private $extension = '';
    private $pbxSrv = '';

    private $outTech = "SIP";
    private $localTech = "Local";

    private function __construct(string $type, array $data)
    {
        $this->type = $type;
        $this->data = $data;

        $this->callerid = $data['callerid'];
        $this->pbxSrv = $data['pbx-srv'];
        if ($data['timeout']) {
            $this->timeout = $data['timeout'];
        }
        if ($type == 'number') {
            $this->number = $data['number'];
            $this->trunk = $data['trunk'];
        } elseif ($type == 'dialplan') {
            $this->context = $data['context'];
            $this->extension = $data['extension'];
        }
    }

    public function getChannel()
    {
        $channel = "";
        if ($this->type == 'number') {
            if ($this->trunk) {
                $channel = "{$this->outTech}/{$this->trunk}/{$this->number}";
            } else {
                $channel = "{$this->outTech}/{$this->number}";
            }
        } elseif ($this->type == 'dialplan') {
            $channel = "{$this->localTech}/{$this->extension}@{$this->context}";
        }
        return $channel;
    }

    public function getType()
    {
        return $this->type;
    }

    public function getNumber()
    {
        return $this->number;
    }

    public function getTrunk()
    {
        return $this->trunk;
    }

    public function getCallerid()
    {
        return $this->callerid;
    }

    public function getTimeout()
    {
        return $this->timeout;
    }

    public function getContext()
    {
        return $this->context;
    }

    public function getExtension()
    {
        return $this->extension;
    }

    public function getOutTech()
    {
        return $this->outTech;
    }

    public function getApplication()
    {
        return "Dial";
    }

    public function getData()
    {
        return $this->getChannel() . "," . $this->getTimeout();
    }

    public function getPbxSrv()
    {
        return $this->pbxSrv;
    }

    /**
     * Creates new instance of class
     * @return Result
     */
    public static function create(array $data)
    {
        $rslt = self::validate($data);
        if ($rslt->error()) {
            return new ResultError($rslt->errorDesc());
        }
        $origData = $rslt->data();
        return new ResultOK(new self($origData['type'], $origData));
    }

    /**
     * Checks givet $target against correct originate parameters
     * @return Result
     * */
    private static function validate(array $target)
    {
        $tgtType = isset($target['type'])?$target['type']:false;
        if (!$tgtType) {
            return new ResultError("Target type not set");
        }

        $validTypes = ['number', 'dialplan'];
        if (!in_array($tgtType, $validTypes)) {
            return new ResultError("Invalid target type: " . $tgtType);
        }

        $callerid = isset($target['callerid'])?$target['callerid']:false;
        $timeout  = isset($target['timeout'])?$target['timeout']:false;
        $pbxSrv   = isset($target['pbx-srv'])?$target['pbx-srv']:false;

        if ($tgtType == "number") {
            $number = isset($target['number'])?$target['number']:false;
            if (!$number) {
                return new ResultError("Number not set");
            }
            $trunk = isset($target['trunk'])?$target['trunk']:false;
            return new ResultOK(['type' => $tgtType, 'number' => "$number", 'trunk' => "$trunk",
            'callerid' => "$callerid",
            'timeout' => "$timeout",
            'pbx-srv' => "$pbxSrv", ]);
        } elseif ($tgtType == "dialplan") {
            $context = isset($target['context'])?$target['context']:false;
            $extension = isset($target['extension'])?$target['extension']:false;
            if (!$context || !$extension) {
                return new ResultError("Context or extension not set");
            }
            return new ResultOK(['type' => $tgtType, 'context' => "$context", 'extension' => "$extension",
            'callerid' => "$callerid",
            'timeout' => "$timeout",
            'pbx-srv' => "$pbxSrv", ]);
        }
        return new ResultError("something went wrong if we here");
    }

    public function getKeys()
    {
        $retKeys = [];
        foreach ($this->data as $k => $v) {
            if ($v) {
                $retKeys[$k] = $v;
            }
        }
        return $retKeys;
    }
}
