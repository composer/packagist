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

use App\Entity\OrganizationInvitationRepository;
use App\Organization\EventStore\ConcurrencyException;
use App\Organization\InvitationManager;
use App\Service\Locker;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Cron sweep that resolves pending organization invitations to `expired` once due. Expiry is already
 * enforced lazily on read, so this only keeps the persisted status and audit log honest by recording a
 * {@see \App\Organization\Domain\Event\UserInvitationExpired} event with a system actor.
 */
#[AsCommand(name: 'packagist:expire-organization-invitations', description: 'Mark pending organization invitations as expired once their link is no longer valid')]
class ExpireOrganizationInvitationsCommand extends Command
{
    /** Upper bound on invitations resolved in a single run; a larger backlog drains over the next runs. */
    private const int MAX_PER_RUN = 1000;

    public function __construct(
        private readonly OrganizationInvitationRepository $invitations,
        private readonly InvitationManager $invitationManager,
        private readonly ClockInterface $clock,
        private readonly Locker $locker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'List the invitations that would be expired without changing anything');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $output->writeln('ℹ️ DRY RUN');
        }

        if (!$this->locker->lockCommand(__CLASS__)) {
            $output->writeln('<comment>Aborting, another instance is already running.</comment>');

            return Command::SUCCESS;
        }

        try {
            $due = $this->invitations->findPendingExpired($this->clock->now(), self::MAX_PER_RUN);

            if ($due === []) {
                $output->writeln('No pending invitations past expiry.');

                return Command::SUCCESS;
            }

            $expired = 0;
            $skipped = 0;

            foreach ($due as $invitation) {
                if ($dryRun) {
                    $output->writeln(\sprintf('Would expire %s for %s (expired at %s)', $invitation->id->toBase32(), $invitation->email, $invitation->expiresAt->format('Y-m-d H:i')));

                    continue;
                }

                try {
                    $this->invitationManager->expire($invitation);
                    $expired++;
                } catch (ConcurrencyException $e) {
                    // Resolved concurrently; the next run re-checks.
                    $skipped++;
                    $output->writeln(\sprintf('<comment>Skipped %s: %s</comment>', $invitation->id->toBase32(), $e->getMessage()));
                }
            }

            if ($dryRun) {
                $capped = \count($due) === self::MAX_PER_RUN ? ' (capped, more may remain)' : '';
                $output->writeln(\sprintf('Found %d pending invitation(s) past expiry%s.', \count($due), $capped));

                return Command::SUCCESS;
            }

            $output->writeln(\sprintf('Expired %d invitation(s).', $expired));

            if ($skipped > 0) {
                $output->writeln(\sprintf('<comment>%d invitation(s) skipped due to concurrent modification.</comment>', $skipped));
            }

            return Command::SUCCESS;
        } finally {
            $this->locker->unlockCommand(__CLASS__);
        }
    }
}
