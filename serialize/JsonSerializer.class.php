<?php

namespace CCS\serialize;

/**
 * Provides class serializing to/from json
 */
class JsonSerializer implements ISerializer
{
    public function serialize(array $data)
    {
        return json_encode($data, JSON_PRETTY_PRINT);
    }

    public function unserialize(string $data)
    {
        return json_decode($data, true);
    }
}
