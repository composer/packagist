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

use App\Audit\AuditRecordType;
use App\Entity\AuditRecord;
use App\Entity\User;
use App\Security\EmailVerifier;
use App\Tests\IntegrationTestCase;
use Symfony\Component\HttpFoundation\Request;
use SymfonyCasts\Bundle\VerifyEmail\Exception\VerifyEmailExceptionInterface;
use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

class EmailVerifierTest extends IntegrationTestCase
{
    private EmailVerifier $emailVerifier;
    private VerifyEmailHelperInterface $verifyEmailHelper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->verifyEmailHelper = static::getContainer()->get(VerifyEmailHelperInterface::class);
        $this->emailVerifier = static::getContainer()->get(EmailVerifier::class);
    }

    public function testHandleEmailConfirmationSuccess(): void
    {
        $user = self::createUser('user', 'user@example.org', enabled: false);
        $this->store($user);

        $this->assertFalse($user->isEnabled());

        $signatureComponents = $this->verifyEmailHelper->generateSignature(
            'register_confirm_email',
            (string) $user->getId(),
            $user->getEmail(),
            ['id' => $user->getId()]
        );

        $request = Request::create($signatureComponents->getSignedUrl());
        $this->emailVerifier->handleEmailConfirmation($request, $user);

        $this->assertTrue($user->isEnabled());

        $em = self::getEM();
        $record = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditRecordType::UserVerified, 'userId' => $user->getId()]);
        $this->assertNotNull($record, 'No audit record was created');
        $this->assertSame('user', $record->attributes['user']['username']);
        $this->assertSame('user@example.org', $record->attributes['email']);
    }
}
