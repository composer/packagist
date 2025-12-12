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

namespace App\Audit;

enum UserRegistrationMethod: string
{
    case REGISTRATION_FORM = 'form';
    case OAUTH_GITHUB = 'github-oauth';

    public function translationKey(): string
    {
        return 'audit_log.enums.user-registration-method.' . $this->value;
    }
}
