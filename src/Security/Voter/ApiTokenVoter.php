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

namespace App\Security\Voter;

use App\Security\Api\ApiToken;
use App\Security\Api\ApiType;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<value-of<ApiType>, mixed>
 */
class ApiTokenVoter extends Voter
{
    protected function supports(string $attribute, mixed $subject): bool
    {
        return ApiType::tryFrom($attribute) instanceof ApiType;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if (!$token instanceof ApiToken) {
            return false;
        }

        return ApiType::Safe === ApiType::from($attribute) || ApiType::Unsafe === $token->type;
    }

    public function supportsAttribute(string $attribute): bool
    {
        return ApiType::tryFrom($attribute) instanceof ApiType;
    }
}
