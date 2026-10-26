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

use App\Entity\SocketSupplyChainAlert;
use App\Entity\SocketSupplyChainAlertRepository;
use App\Service\Locker;
use App\Socket\SocketThreatFeed;
use App\Socket\SupplyChainAlertType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'packagist:socket-supply-chain-alerts', description: 'Updates the supply chain alerts Socket reports, shown on package pages')]
class UpdateSocketSupplyChainAlertsCommand extends Command
{
    public function __construct(
        private SocketThreatFeed $threatFeed,
        private SocketSupplyChainAlertRepository $alertRepo,
        private Locker $locker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->locker->lockCommand(__CLASS__)) {
            return Command::SUCCESS;
        }

        try {
            $alerts = [];
            foreach (SupplyChainAlertType::cases() as $type) {
                $threats = $this->threatFeed->getThreats($type->feedFilter());
                if ($threats === null) {
                    $output->writeln('<error>Failed to fetch the Socket threat feed for '.$type->value.', keeping the existing alerts</error>');

                    return Command::FAILURE;
                }

                foreach ($threats as $threat) {
                    // possible_* and unreviewed findings are left out, as for the malware list
                    if ($threat->type !== $type->value || $threat->needsHumanReview) {
                        continue;
                    }
                    if (\strlen($threat->packageName) > 191 || \strlen($threat->version) > 255) {
                        continue;
                    }

                    // keyed the way the unique index compares (case-insensitive, trailing spaces ignored), so spellings
                    // MySQL treats as one version cannot both reach the insert and fail the whole run
                    $key = $threat->packageName.'@'.mb_strtolower(rtrim($threat->version)).'@'.$type->value;
                    $existing = $alerts[$key] ?? null;
                    if ($existing !== null && ($threat->reportedAt === null || ($existing->reportedAt !== null && $existing->reportedAt <= $threat->reportedAt))) {
                        continue;
                    }

                    $alerts[$key] = new SocketSupplyChainAlert(
                        $threat->packageName,
                        $threat->version,
                        $type,
                        $threat->link,
                        $threat->reportedAt,
                    );
                }
            }

            $this->alertRepo->replaceAll(array_values($alerts));
            $output->writeln('Stored '.\count($alerts).' Socket supply chain alerts', OutputInterface::VERBOSITY_VERBOSE);

            return Command::SUCCESS;
        } finally {
            $this->locker->unlockCommand(__CLASS__);
        }
    }
}
