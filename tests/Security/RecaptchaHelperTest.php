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

use App\Security\RecaptchaHelper;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Profile\RedisProfile;
use Symfony\Component\HttpFoundation\Request;

class RecaptchaHelperTest extends TestCase
{
    private RecaptchaHelper $helper;
    /** @var Client&\PHPUnit\Framework\MockObject\MockObject */
    private $redis;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(Client::class);
        $this->helper = new RecaptchaHelper($this->redis, true);
    }

    public function testRequiresRecaptcha(): void
    {
        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with($this->equalTo('mget'))
            ->willReturn([2, 3]);

        $this->assertTrue($this->helper->requiresRecaptcha('127.0.0.1', 'username'));
    }

    public function testIncreaseCounter(): void
    {
        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with($this->equalTo('incrFailedLoginCounter'));

        $this->helper->increaseCounter(new Request());
    }

    public function testClearCounter(): void
    {
        $this->redis
            ->expects($this->once())
            ->method('__call')
            ->with($this->equalTo('del'));

        $this->helper->clearCounter(new Request());
    }
}
