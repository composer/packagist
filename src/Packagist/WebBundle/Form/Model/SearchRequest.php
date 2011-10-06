<?php

namespace Packagist\WebBundle\Form\Model;

class AddMaintainerRequest
{
    protected $query;

    public function setQuery($query)
    {
        $this->query = $query;
    }
    
    public function getQuery()
    {
        return $this->query;
    }
}