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
    private ?string $user = null;

    public function setUser(string $username): void
    {
        $this->user = $username;
    }

    public function getUser(): ?string
    {
        return $this->user;
    }
}
