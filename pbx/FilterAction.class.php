<?php

namespace CCS\pbx;

class FilterAction extends \PAMI\Message\Action\ActionMessage
{
    public function __construct($filter)
    {
        parent::__construct('Filter');
        $this->setKey('Operation', 'Add');
        $this->setKey('Filter', $filter);
    }
}
