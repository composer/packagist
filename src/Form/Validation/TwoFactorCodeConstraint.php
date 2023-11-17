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

namespace App\Form\Validation;

use App\Entity\User;
use Symfony\Component\Validator\Constraint;

class TwoFactorCodeConstraint extends Constraint
{
    public function __construct(
        public readonly User $user,
        public readonly string $message = 'Invalid authenticator code',
    ) {
        parent::__construct();
    }
}
