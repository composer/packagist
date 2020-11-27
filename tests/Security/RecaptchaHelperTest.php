<?php declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\RecaptchaHelper;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Predis\Profile\RedisProfile;
use Symfony\Component\HttpFoundation\Request;

class RecaptchaHelperTest extends TestCase
{
    /** @var RecaptchaHelper */
    private $helper;
    /** @var Client&\PHPUnit\Framework\MockObject\MockObject */
    private $redis;

    protected function setUp(): void
    {
        $this->redis = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->getMock();
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
            ->method('getProfile')
            ->willReturn($this->getMockBuilder(RedisProfile::class)->disableOriginalConstructor()->getMock());

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
