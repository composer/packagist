<?php

namespace Packagist\WebBundle\Form\Model;

use Packagist\WebBundle\Entity\User;

class AddMaintainerForm
{
    protected $user;

    public function setUser(User $user)
    {
        $this->user = $user;
    }

    public function getUser()
    {
        return $this->user;
    }
}