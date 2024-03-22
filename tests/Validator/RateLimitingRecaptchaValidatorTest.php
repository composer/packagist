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

namespace App\Tests\Validator;

use App\Security\RecaptchaContext;
use App\Security\RecaptchaHelper;
use App\Validator\RateLimitingRecaptcha;
use App\Validator\RateLimitingRecaptchaValidator;
use Beelab\Recaptcha2Bundle\Recaptcha\RecaptchaException;
use Beelab\Recaptcha2Bundle\Recaptcha\RecaptchaVerifier;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReCaptcha\Response;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class RateLimitingRecaptchaValidatorTest extends TestCase
{
    private RateLimitingRecaptchaValidator $validator;
    private RecaptchaHelper&MockObject $recaptchaHelper;
    private RecaptchaVerifier&MockObject $recaptchaVerifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new RateLimitingRecaptchaValidator(
            $this->recaptchaHelper = $this->createMock(RecaptchaHelper::class),
            $this->recaptchaVerifier = $this->createMock(RecaptchaVerifier::class),
        );
    }

    public function testValidateWithoutRateLimit(): void
    {
        $this->recaptchaHelper
            ->expects($this->never())
            ->method('requiresRecaptcha')
            ->willReturn(true);

        $this->recaptchaVerifier
            ->expects($this->once())
            ->method('verify');

        $this->validator->validate(null, new RateLimitingRecaptcha());
    }

    public function testValidateWithRateLimit(): void
    {
        $this->recaptchaHelper
            ->expects($this->once())
            ->method('requiresRecaptcha')
            ->willReturn(true);

        $this->recaptchaVerifier
            ->expects($this->once())
            ->method('verify');

        $this->validator->validate(null, new RateLimitingRecaptcha(['onlyShowAfterIncrementTrigger' => true]));
    }

    #[TestWith([true, RateLimitingRecaptcha::INVALID_RECAPTCHA_MESSAGE])]
    #[TestWith([false, RateLimitingRecaptcha::MISSING_RECAPTCHA_MESSAGE])]
    public function testValidateInvalidRecaptcha(bool $hasRecaptcha, string $expectedMessage): void
    {
        $this->recaptchaHelper
            ->expects($this->once())
            ->method('buildContext')
            ->willReturn(new RecaptchaContext('127.0.0.1', 'username', $hasRecaptcha));

        $this->recaptchaHelper
            ->expects($this->once())
            ->method('requiresRecaptcha')
            ->willReturn(true);

        $this->recaptchaVerifier
            ->expects($this->once())
            ->method('verify')
            ->willThrowException(new RecaptchaException(new Response(false)));

        $violationBuilder = $this->createMock(ConstraintViolationBuilderInterface::class);
        $violationBuilder
            ->expects($this->once())
            ->method('setCode')
            ->with($this->identicalTo(RateLimitingRecaptcha::INVALID_RECAPTCHA_ERROR))
            ->willReturn($violationBuilder);

        $context = $this->createMock(ExecutionContextInterface::class);
        $context
            ->expects($this->once())
            ->method('buildViolation')
            ->with($this->identicalTo($expectedMessage))
            ->willReturn($violationBuilder);

        $this->validator->initialize($context);

        $this->validator->validate(null, new RateLimitingRecaptcha(['onlyShowAfterIncrementTrigger' => true]));
    }

    public function testValidateNotRequired(): void
    {
        $this->recaptchaHelper
            ->expects($this->once())
            ->method('requiresRecaptcha')
            ->willReturn(false);

        $this->recaptchaVerifier
            ->expects($this->never())
            ->method('verify');

        $this->validator->validate(null, new RateLimitingRecaptcha(['onlyShowAfterIncrementTrigger' => true]));
    }
}
