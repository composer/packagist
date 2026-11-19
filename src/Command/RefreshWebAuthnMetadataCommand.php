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

namespace App\Command;

use App\Security\WebAuthn\FidoMetadataRepository;
use App\Service\Locker;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'packagist:webauthn:refresh-metadata', description: 'Verify and import the official FIDO metadata feed for attested-key enrollment')]
class RefreshWebAuthnMetadataCommand extends Command
{
    public function __construct(
        private FidoMetadataRepository $metadata,
        private Locker $locker,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->locker->lockCommand(__CLASS__)) {
            return Command::SUCCESS;
        }

        $io = new SymfonyStyle($input, $output);

        try {
            $number = $this->metadata->refresh();
        } catch (\Throwable $e) {
            $this->logger->error('FIDO metadata import failed', ['exception' => $e]);
            $io->error('FIDO metadata import failed: '.$e->getMessage());

            return Command::FAILURE;
        } finally {
            $this->locker->unlockCommand(__CLASS__);
        }

        $io->success('Verified FIDO metadata feed '.$number.' is up to date');

        return Command::SUCCESS;
    }
}
