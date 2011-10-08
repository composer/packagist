<?php

namespace Packagist\WebBundle\Form\Model;

class SearchRequest
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
