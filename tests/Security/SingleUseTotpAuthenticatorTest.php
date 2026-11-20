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

namespace App\Tests\Security;

use App\Security\SingleUseTotpAuthenticator;
use App\Tests\Fixtures\Fixtures;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Response\Status;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticator;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

class SingleUseTotpAuthenticatorTest extends TestCase
{
    use Fixtures;

    public function testAtomicClaimCoversTheEntireWindowIncludingLeeway(): void
    {
        $user = self::createUser();
        $secret = 'JBSWY3DPEHPK3PXP';
        $user->setTotpSecret($secret);
        $clock = new MockClock('2026-11-20 12:00:20 UTC');
        $factory = new TotpFactory(null, null, [], $clock);
        $code = $factory->createTotpForUser($user)->at($clock->now()->getTimestamp() + 10);

        $redis = $this->createMock(Client::class);
        $redis->expects(self::exactly(2))->method('__call')->with('set', [
            'totp-used:'.hash_hmac('sha256', $code, $secret), '1', 'EX', 50, 'NX',
        ])->willReturn(Status::get('OK'), null);

        $authenticator = new SingleUseTotpAuthenticator(new TotpAuthenticator($factory, new EventDispatcher(), 10), $redis, 10);
        self::assertFalse($authenticator->checkCode($user, 'invalid'));
        self::assertTrue($authenticator->checkCode($user, $code));
        $clock->sleep(49);
        self::assertFalse($authenticator->checkCode($user, implode(' ', str_split($code, 3))));
        $clock->sleep(1);
        self::assertFalse($authenticator->checkCode($user, $code));
    }
}
