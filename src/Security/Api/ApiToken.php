<?php declare(strict_types=1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Security\Api;

use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\AbstractToken;

class ApiToken extends AbstractToken
{
    public function __construct(User $user, public readonly ApiType $type)
    {
        // API tokens skip 2FA, so admin roles never apply to them
        parent::__construct(['ROLE_USER']);
        $this->setUser($user);
    }
}
