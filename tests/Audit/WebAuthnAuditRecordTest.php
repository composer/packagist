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

namespace App\Tests\Audit;

use App\Entity\AuditRecord;
use App\Entity\WebAuthnCredential;
use App\Log\AuditLogEventType;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class WebAuthnAuditRecordTest extends TestCase
{
    #[TestWith([AuditLogEventType::PasswordChanged])]
    #[TestWith([AuditLogEventType::PackageCreated])]
    public function testRejectsUnrelatedEventTypes(AuditLogEventType $type): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected a WebAuthn credential event type.');

        AuditRecord::webAuthnCredentialChanged($this->createStub(WebAuthnCredential::class), $type);
    }

    public function testRenameRequiresThePreviousName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A credential rename requires its previous name.');

        AuditRecord::webAuthnCredentialChanged($this->createStub(WebAuthnCredential::class), AuditLogEventType::WebAuthnCredentialRenamed);
    }
}
