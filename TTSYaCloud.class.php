<?php

namespace CCS;

// phpcs:disable PSR1.Classes.ClassDeclaration.MissingNamespace, PSR2.Classes.PropertyDeclaration.Underscore
class TTSYaCloud
{
    private $_message = '';

    // default config
    /** @var mixed */
    private $config = [];

    private $folderId = "b1g4phkd32er5ouj5o52";
    private $OAuthToken = "AQAAAAA0RLnKAATuwecNm4VdWE20mZicGLPQiwk";

    private $IAMToken = '';

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
        $this->config['format'] = 'wav';

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

        $ret = $this->getIAMToken();

        if ($ret['result'] == 'error') {
            return new ResultError("Error get IAMToken: " . $ret['message']);
        }

        $tokenData = json_decode($ret['data'], true);

        $IAMToken = $tokenData['iamToken'] ?? false;

        if (!$IAMToken) {
            return new ResultError("Error decoding IAMToken: " . print_r($ret, true));
        }

        $this->IAMToken = $IAMToken;

        $ret = $this->getTTSAudioRaw();

        if ($ret['result'] == 'error') {
            return new ResultError("Error getting raw audio: " . $ret['message']);
        }

        $ttsFilenameRaw = '/tmp/' . md5($this->_message) . '.raw';
        $bytesWritten = @file_put_contents($ttsFilenameRaw, $ret['data']);
        if ($bytesWritten === false) {
            return new ResultError("Could not store file to $ttsFilenameRaw");
        }

        $cmd = "/usr/bin/sox -r 48000 -b 16 -e signed-integer -c 1 $ttsFilenameRaw -r 8000 -c 1 $ttsFilename";
        $output = [];
        $return_var = 0;

        exec($cmd, $output, $return_var);
        if ($return_var) {
            return new ResultError("Error convert $ttsFilenameRaw -> $ttsFilename");
        }

        @unlink($ttsFilenameRaw);

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

    private function curlExec($url, $post = false, $headers = [])
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        if ($post !== false) {
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $curl_err = curl_error($ch);
            curl_close($ch);
            return ['result' => 'error', 'message' => $curl_err];
        }

        if (curl_getinfo($ch, CURLINFO_HTTP_CODE) != 200) {
            curl_close($ch);
            return ['result' => 'error', 'message' => $response];
        }

        curl_close($ch);
        return ['result' => 'ok', 'data' => $response];
    }

    private function getIAMToken()
    {
        $url = "https://iam.api.cloud.yandex.net/iam/v1/tokens";
        $post = '{"yandexPassportOauthToken": "' . $this->OAuthToken . '"}';
        $headers = ['Content-Type: application/json'];
        return $this->curlExec($url, $post, $headers);
    }

    private function getTTSAudioRaw()
    {
        $FORMAT_PCM = "lpcm";
        $FORMAT_OPUS = "oggopus";

        $folderId = $this->folderId;
        $url = "https://tts.api.cloud.yandex.net/speech/v1/tts:synthesize";

        $post = 'text="'.urlencode($this->_message).'"'
                    .'&lang='.$this->config['lang']
                    .'&voice='.$this->config['voice']
                    .'&speed='.$this->config['speed']
                    .'&emotion='.$this->config['emotion']
                    ."&folderId=${folderId}"
                    ."&sampleRateHertz=48000"
                    ."&format=$FORMAT_PCM";

        $headers = ['Authorization: Bearer ' . $this->IAMToken];
        return $this->curlExec($url, $post, $headers);
    }
}
