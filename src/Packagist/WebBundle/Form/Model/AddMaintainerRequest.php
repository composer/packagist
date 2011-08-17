<?php

namespace Packagist\WebBundle\Form\Model;

use FOS\UserBundle\Model\UserInterface;

class AddMaintainerRequest
{
    protected $user;

    public function setUser(UserInterface $user)
    {
        $this->user = $user;
    }

    public function getUser()
    {
        return $this->user;
    }
}