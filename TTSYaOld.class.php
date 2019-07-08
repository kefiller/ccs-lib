<?php

namespace CCS;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, PSR2.Classes.PropertyDeclaration.Underscore
class TTS
{
    private $_message = '';

    // default config
    /** @var mixed */
    private $config = [
        // 'internal-root' => '/cc_records/tts', // root path fot storing tts
        // 'rec-dir-with-date' => true, // add date dirs to root, ex. /cc_records/tts/2018/08/28
        // 'url' => 'https://tts.voicetech.yandex.net/generate',
        // 'format' => 'mp3',
        // 'lang' => 'ru-RU',
        // 'speaker' => 'zahar',
        // 'speed' => '0.7',
        // 'quality' => 'hi',
        // 'key' => '2b32b23c-8345-4b99-9c4a-831be65082f3',
        // 'emotion' => 'evil',
    ];

    public function __construct(string $message, array $defaultConfig, array $customConfig = [])
    {
        if ($message == '') {
            throw new \Exception("Empty message");
        }
        $this->_message = $message;

        if (empty($defaultConfig)) {
            throw new \Exception("No TTS settings");
        }

        // Load default config from global config
        $this->config = $defaultConfig;

        // replace default config values with custom values (if any)
        foreach ($customConfig as $key => $value) {
            if (!$value) { // skip empty items
                continue;
            }
            $this->config[$key] = $value;
        }
        $ttsInternalRoot = $this->config['internal-root'];
        if (!is_writable($ttsInternalRoot)) {
            throw new \Exception("'" . $ttsInternalRoot . "' (internal-root) is non-writable or doesnt exists");
        }
    }

    /** @return Result */
    public function generate()
    {
        $ttsFilename    = $this->getRecAbsName();
        $recDir = $this->getRecDir();

        // create folder if not exists
        if (!file_exists($recDir)) {
            if (!@mkdir($recDir, 0777, true)) {
                return new ResultError("Could not create dir $recDir");
            }
        }

        $fullUrl = $this->config['url'].'?text="'.rawurlencode($this->_message).'"'
                    .'&format='.$this->config['format']
                    .'&lang='.$this->config['lang']
                    .'&speaker='.$this->config['speaker']
                    .'&key='.$this->config['key']
                    .'&speed='.$this->config['speed']
                    .'&quality='.$this->config['quality']
                    .'&emotion='.$this->config['emotion'];

        $data = @file_get_contents($fullUrl);
        if (!$data) {
            return new ResultError($http_response_header[0]);
        }

        $bytesWritten = @file_put_contents($ttsFilename, $data);
        if ($bytesWritten === false) {
            return new ResultError("Could not store file to $ttsFilename");
        }
        return new ResultOK();
    }

    /** @return Result */
    public function get(bool $autoGen = true)
    {
        if ($this->recordExists()) {
            return new ResultOK($this->getRecAbsName());
        }
        if (!$autoGen) {
            return new ResultError("Record is not generated");
        }

        $ret = $this->generate();
        if ($ret->error()) {
            return $ret;
        }
        return new ResultOK($this->getRecAbsName());
    }

    public function recordExists()
    {
        return file_exists($this->getRecAbsName());
    }

    public function getRecName()
    {
        return md5($this->_message);
    }

    public function getRecDir()
    {
        return $this->config['internal-root'] . "/" . $this->getRecTailDir();
    }

    public function getRecTailDir()
    {
        if ($this->config['rec-dir-with-date']) {
            return date("Y/m/d");
        }
        return '';
    }

    public function getRecTailName()
    {
        return $this->getRecTailDir() . "/" . $this->getRecName() . "." . $this->config["format"];
    }

    public function getRecAbsName()
    {
        return $this->getRecDir() . "/" . $this->getRecName() . "." . $this->config["format"];
    }
}
