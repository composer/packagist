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

namespace App\Security\WebAuthn;

use App\Entity\User;
use Scheb\TwoFactorBundle\Security\TwoFactor\AuthenticationContextInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\DefaultTwoFactorFormRenderer;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorFormRendererInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\TwoFactorProviderInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Twig\Environment;

#[AutoconfigureTag('scheb_two_factor.provider', ['alias' => 'webauthn'])]
class WebAuthnTwoFactorProvider implements TwoFactorProviderInterface
{
    public function __construct(private WebAuthnManager $webauthn, private Environment $twig)
    {
    }

    public function beginAuthentication(AuthenticationContextInterface $context): bool
    {
        $user = $context->getUser();

        return $user instanceof User && $user->getWebAuthnCredentials() !== [];
    }

    public function needsPreparation(): bool
    {
        return false;
    }

    public function prepareAuthentication(object $user): void
    {
    }

    public function validateAuthenticationCode(object $user, string $authenticationCode): bool
    {
        if (!$user instanceof User) {
            return false;
        }
        try {
            $this->webauthn->authenticate($user, $authenticationCode);

            return true;
        } catch (ExpiredWebAuthnChallengeException|ServiceUnavailableHttpException $e) {
            throw new CustomUserMessageAuthenticationException($e->getMessage(), previous: $e);
        } catch (BadCredentialsException) {
            return false;
        }
    }

    public function getFormRenderer(): TwoFactorFormRendererInterface
    {
        return new DefaultTwoFactorFormRenderer($this->twig, 'user/webauthn_login.html.twig');
    }
}
