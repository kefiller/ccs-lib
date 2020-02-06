<?php

namespace CCS\serialize;

/**
 * Provides class serializing to/from xml
 */
class XMLSerializer implements ISerializer
{
    public function serialize(array $data)
    {
        return $this->xmlEncode($data);
    }

    // @phan-suppress-next-line PhanUnusedPublicMethodParameter
    public function unserialize(string $data)
    {
        // just stub
        return null;
    }

    private function xmlEncode(array $array)
    {
        //creating object of SimpleXMLElement
        $xmlElem = new \SimpleXMLElement("<?xml version=\"1.0\"?><data></data>");
        $this->arrayToXml($array, $xmlElem);
        return $xmlElem->asXML();
    }
    private function arrayToXml(array $array, &$xmlElem)
    {
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                if (!is_numeric($key)) {
                    $subnode = $xmlElem->addChild("$key");
                    $this->arrayToXml($value, $subnode);
                } else {
                    $subnode = $xmlElem->addChild("item$key");
                    $this->arrayToXml($value, $subnode);
                }
            } else {
                $xmlElem->addChild("$key", htmlspecialchars("$value"));
            }
        }
    }
}
