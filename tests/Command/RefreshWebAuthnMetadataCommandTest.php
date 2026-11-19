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

namespace App\Tests\Command;

use App\Command\RefreshWebAuthnMetadataCommand;
use App\Security\WebAuthn\FidoMetadataRepository;
use App\Service\Locker;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class RefreshWebAuthnMetadataCommandTest extends TestCase
{
    public function testConcurrentImportExitsSilently(): void
    {
        $metadata = $this->createMock(FidoMetadataRepository::class);
        $metadata->expects(self::never())->method('refresh');
        $locker = $this->createMock(Locker::class);
        $locker->expects(self::once())->method('lockCommand')->with(RefreshWebAuthnMetadataCommand::class)->willReturn(false);
        $locker->expects(self::never())->method('unlockCommand');
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $tester = new CommandTester(new RefreshWebAuthnMetadataCommand($metadata, $locker, $logger));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertSame('', $tester->getDisplay());
    }

    public function testSuccessfulImportReturnsSuccessWithoutLoggingAnError(): void
    {
        $metadata = $this->createMock(FidoMetadataRepository::class);
        $metadata->expects(self::once())->method('refresh')->willReturn(302);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('error');
        $locker = $this->createMock(Locker::class);
        $locker->expects(self::once())->method('lockCommand')->with(RefreshWebAuthnMetadataCommand::class)->willReturn(true);
        $locker->expects(self::once())->method('unlockCommand')->with(RefreshWebAuthnMetadataCommand::class);
        $tester = new CommandTester(new RefreshWebAuthnMetadataCommand($metadata, $locker, $logger));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Verified FIDO metadata feed 302 is up to date', $tester->getDisplay());
    }

    public function testFailedImportLogsTheExceptionAndReturnsFailure(): void
    {
        $failure = new \RuntimeException('Feed verification failed');
        $metadata = $this->createMock(FidoMetadataRepository::class);
        $metadata->expects(self::once())->method('refresh')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')->with('FIDO metadata import failed', ['exception' => $failure]);
        $locker = $this->createMock(Locker::class);
        $locker->expects(self::once())->method('lockCommand')->with(RefreshWebAuthnMetadataCommand::class)->willReturn(true);
        $locker->expects(self::once())->method('unlockCommand')->with(RefreshWebAuthnMetadataCommand::class);
        $tester = new CommandTester(new RefreshWebAuthnMetadataCommand($metadata, $locker, $logger));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('FIDO metadata import failed: '.$failure->getMessage(), $tester->getDisplay());
    }
}
