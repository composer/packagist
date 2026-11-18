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

namespace App\Tests\Security\Voter;

use App\Entity\User;
use App\Security\Voter\WebAuthnVoter;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

class WebAuthnVoterTest extends TestCase
{
    #[TestWith([WebAuthnVoter::ENROLL])]
    #[TestWith([WebAuthnVoter::VIEW])]
    public function testAdminAccessIsCheckedAgainstThePassedToken(string $attribute): void
    {
        $token = $this->createStub(TokenInterface::class);
        $token->method('getUser')->willReturn(new User());
        $decisions = $this->createMock(AccessDecisionManagerInterface::class);
        $decisions->expects($this->once())->method('decide')->with($token, ['ROLE_ADMIN'])->willReturn(true);
        $voter = new WebAuthnVoter($decisions);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, null, [$attribute]));
    }
}
