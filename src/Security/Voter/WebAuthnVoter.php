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
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\OfflineTokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/** @extends Voter<string, null> */
class WebAuthnVoter extends Voter
{
    public const string ENROLL = 'WEBAUTHN_ENROLL';
    public const string VIEW = 'WEBAUTHN_VIEW';

    public function __construct(private AccessDecisionManagerInterface $accessDecisionManager)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $subject === null && \in_array($attribute, [self::ENROLL, self::VIEW], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || $token instanceof TwoFactorTokenInterface || $token instanceof OfflineTokenInterface) {
            return false;
        }

        // TODO: Remove this voter and its rollout checks when WebAuthn enrollment is released
        // Restore ROLE_USER on enrollment endpoints and show the tab and card to all signed-in users
        return ($attribute === self::VIEW && $user->getWebAuthnCredentials() !== [])
            || $this->accessDecisionManager->decide($token, ['ROLE_ADMIN']);
    }
}
