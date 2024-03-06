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

use App\Security\RecaptchaContext;
use App\Security\RecaptchaHelper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class RecaptchaHelperTest extends TestCase
{
    private RecaptchaHelper $helper;
    private Client&MockObject $redis;

    protected function setUp(): void
    {
        $this->helper = new RecaptchaHelper(
            $this->redis = $this->createMock(Client::class),
            true,
            $this->createMock(RequestStack::class),
            $this->createMock(TokenStorageInterface::class),
            $this->createMock(AuthenticationUtils::class),
        );
    }

    public function testRequiresRecaptcha(): void
    {
        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with($this->equalTo('mget'))
            ->willReturn([2, 3]);

        $context = new RecaptchaContext('127.0.0.1', 'username', false);

        $this->assertTrue($this->helper->requiresRecaptcha($context));
    }

    public function testIncreaseCounter(): void
    {
        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with($this->equalTo('incrFailedLoginCounter'));

        $context = new RecaptchaContext('127.0.0.1', 'username', false);

        $this->helper->increaseCounter($context);
    }

    public function testClearCounter(): void
    {
        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with($this->equalTo('del'));

        $context = new RecaptchaContext('127.0.0.1', 'username', false);

        $this->helper->clearCounter($context);
    }
}
