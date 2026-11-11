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

use App\Entity\User;
use App\Security\SecondFactorProofStore;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\OfflineTokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, null> */
class SecondFactorVoter extends Voter
{
    public const string IS_AUTHENTICATED_2FA = 'IS_AUTHENTICATED_2FA';
    public const string IS_AUTHENTICATED_2FA_RECENTLY = 'IS_AUTHENTICATED_2FA_RECENTLY';

    // Groundwork for actions that will require a verified hardware key
    // No actions check these attributes yet
    public const string IS_AUTHENTICATED_HARDWARE_KEY = 'IS_AUTHENTICATED_HARDWARE_KEY';
    public const string IS_AUTHENTICATED_HARDWARE_KEY_RECENTLY = 'IS_AUTHENTICATED_HARDWARE_KEY_RECENTLY';

    public function __construct(private SecondFactorProofStore $secondFactorProofStore)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject === null && \in_array($attribute, [
            self::IS_AUTHENTICATED_2FA, self::IS_AUTHENTICATED_2FA_RECENTLY,
            self::IS_AUTHENTICATED_HARDWARE_KEY, self::IS_AUTHENTICATED_HARDWARE_KEY_RECENTLY,
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || $token instanceof TwoFactorTokenInterface || $token instanceof OfflineTokenInterface) {
            return false;
        }

        return match ($attribute) {
            self::IS_AUTHENTICATED_2FA => $this->secondFactorProofStore->hasProof($user),
            self::IS_AUTHENTICATED_2FA_RECENTLY => $this->secondFactorProofStore->hasRecentProof($user),
            self::IS_AUTHENTICATED_HARDWARE_KEY => $this->secondFactorProofStore->hasProof($user, SecondFactorProofStore::HARDWARE_KEY),
            self::IS_AUTHENTICATED_HARDWARE_KEY_RECENTLY => $this->secondFactorProofStore->hasRecentProof($user, SecondFactorProofStore::HARDWARE_KEY),
            default => false,
        };
    }
}
