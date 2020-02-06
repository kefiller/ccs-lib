<?php

namespace CCS\proto;

class HTTPCurl
{
    private $curlHandle = null;
    private $url = null;

    public function __construct($url)
    {
        $this->url = $url;
        $this->curlHandle = curl_init();
    }

    public function __destruct()
    {
        curl_close($this->curlHandle);
    }

    private function setCurlStdOptions()
    {
        curl_reset($this->curlHandle);

        // @phan-suppress-next-line PhanUndeclaredConstant
        curl_setopt($this->curlHandle, CURLOPT_URL, $this->url);
        // @phan-suppress-next-line PhanUndeclaredConstant
        curl_setopt($this->curlHandle, CURLOPT_HEADER, 0);
        // @phan-suppress-next-line PhanUndeclaredConstant
        curl_setopt($this->curlHandle, CURLOPT_RETURNTRANSFER, true);
        // @phan-suppress-next-line PhanUndeclaredConstant
        curl_setopt($this->curlHandle, CURLOPT_TIMEOUT, 2);
        // @phan-suppress-next-line PhanUndeclaredConstant
        curl_setopt($this->curlHandle, CURLOPT_POST, 1);
    }

    private function curlExec()
    {
        $curlResult = curl_exec($this->curlHandle);
        //$this->setErrorDesc(curl_error($this->curlHandle));
        return $curlResult;
    }

    public function get()
    {
        $this->setCurlStdOptions();
        return $this->curlExec();
    }

    public function put($data)
    {
        $this->setCurlStdOptions();

        //curl_setopt($this->curlHandle, CURLOPT_CUSTOMREQUEST, "PUT");
        // @phan-suppress-next-line PhanUndeclaredConstant
        curl_setopt($this->curlHandle, CURLOPT_POSTFIELDS, $data);

        return $this->curlExec();
    }
}
