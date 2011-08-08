<?php

namespace Packagist\WebBundle\Form\Model;

class AddMaintainerForm
{
    protected $username;

    public function setUsername($username)
    {
        $this->username = $username;
    }

    public function getUsername()
    {
        return $this->username;
    }
}