<?php

namespace CCS\serialize;

/**
 * Interface to serializable objects
 */
interface ISerializer
{
    public function serialize(array $data);
    public function unserialize(string $data);
}
