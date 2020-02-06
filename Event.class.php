<?php

namespace CCS;

use CCS\serialize\JsonSerializer;
use CCS\serialize\ISerializer;

class Event
{
    private $type;
    private $data;
    /** @var ISerializer */
    private $serializer;

    public function __construct(string $type, array $data)
    {
        $this->type = $type;
        $this->data = $data;
        $this->serializer = new JsonSerializer(); // for now so
    }

    public function getType()
    {
        return $this->type;
    }

    public function getData()
    {
        return $this->data;
    }

    public function serialize()
    {
        return $this->serializer->serialize([
            'event' => $this->getType(),
            'data' => $this->getData(),
        ]);
    }
}
