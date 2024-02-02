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

namespace App\Validator;

use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\Validator\Constraint;

class TwoFactorCode extends Constraint
{
    public function __construct(
        public readonly TwoFactorInterface $user,
        public readonly string $message = 'Invalid authenticator code',
    ) {
        parent::__construct();
    }
}
