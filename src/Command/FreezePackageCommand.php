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

use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Model\PackageManager;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'packagist:freeze', description: 'Freezes a package, suppressing it if the reason is spam or malware')]
class FreezePackageCommand extends Command
{
    use DoctrineTrait;

    public function __construct(
        private ManagerRegistry $doctrine,
        private PackageManager $packageManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $reasons = implode(', ', array_map(static fn (PackageFreezeReason $reason) => $reason->value, PackageFreezeReason::casesForRole(true)));

        $this
            ->setDefinition([
                new InputArgument('package', InputArgument::REQUIRED, 'Package name to freeze'),
                new InputArgument('reason', InputArgument::REQUIRED, 'Freeze reason, one of: '.$reasons),
            ])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('package');

        $reason = PackageFreezeReason::tryFrom($input->getArgument('reason'));
        if ($reason === null || !\in_array($reason, PackageFreezeReason::casesForRole(true), true)) {
            $output->writeln('<error>Invalid freeze reason '.$input->getArgument('reason').'</error>');

            return Command::FAILURE;
        }

        $package = $this->getEM()->getRepository(Package::class)->findOneBy(['name' => $name]);
        if ($package === null) {
            $output->writeln('<error>Package '.$name.' not found</error>');

            return Command::FAILURE;
        }

        if ($package->isFrozen()) {
            $output->writeln('<error>Package '.$name.' is already frozen ('.$package->getFreezeReason()?->value.')</error>');

            return Command::FAILURE;
        }

        $package->auditActorFallback = 'admin';
        $this->packageManager->freeze($package, $reason);

        $output->writeln('<info>Package '.$name.' has been frozen ('.$reason->value.')</info>');

        return Command::SUCCESS;
    }
}
