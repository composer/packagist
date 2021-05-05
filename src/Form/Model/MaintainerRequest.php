<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Form\Model;

use App\Entity\User;

class MaintainerRequest
{
    protected $user;

    public function setUser(string $username)
    {
        $this->user = $username;
    }

    public function getUser()
    {
        return $this->user;
    }
}
