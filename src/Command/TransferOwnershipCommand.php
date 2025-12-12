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

use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\User;
use App\Model\PackageManager;
use App\Util\DoctrineTrait;
use Composer\Console\Input\InputOption;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TransferOwnershipCommand extends Command
{
    use DoctrineTrait;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly PackageManager $packageManager,
    )
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:transfer-ownership')
            ->setDescription('Transfer all packages of a vendor')
            ->setDefinition([
                new InputArgument('vendorOrPackage', InputArgument::REQUIRED,'Vendor or package name'),
                new InputArgument('maintainers', InputArgument::IS_ARRAY|InputArgument::REQUIRED, 'The usernames of the new maintainers'),
                new InputOption('dry-run', null, InputOption::VALUE_NONE, 'Dry run'),
            ])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $output->writeln('ℹ️ DRY RUN');
        }

        $vendorOrPackage = $input->getArgument('vendorOrPackage');
        $maintainers = $this->queryAndValidateMaintainers($input, $output);

        if (!count($maintainers)) {
            return Command::FAILURE;
        }

        $packages = $this->queryPackages($vendorOrPackage);

        if (!count($packages)) {
            $output->writeln(sprintf('<error>No packages found for %s</error>', $vendorOrPackage));
            return Command::FAILURE;
        }

        $this->outputPackageTable($output, $packages, $maintainers);

        if (!$dryRun) {
            $this->transferOwnership($packages, $maintainers);
        }

        return Command::SUCCESS;
    }

    /**
     * @return User[]
     */
    private function queryAndValidateMaintainers(InputInterface $input, OutputInterface $output): array
    {
        $usernames = array_map('mb_strtolower', $input->getArgument('maintainers'));
        sort($usernames);

        $maintainers = $this->getEM()->getRepository(User::class)->findEnabledUsersByUsername($usernames, ['usernameCanonical' => 'ASC']);

        if (array_keys($maintainers) === $usernames) {
            return $maintainers;
        }

        $notFound = [];

        foreach ($usernames as $username) {
            if (!array_key_exists($username, $maintainers)) {
                $notFound[] = $username;
            }
        }

        sort($notFound);

        $output->writeln(sprintf('<error>%d maintainers could not be found: %s</error>', count($notFound), implode(', ', $notFound)));

        return [];
    }

    /**
     * @return Package[]
     */
    private function queryPackages(string $vendorOrPackage): array
    {
        $repository = $this->getEM()->getRepository(Package::class);
        $isPackageName = str_contains($vendorOrPackage, '/');

        if ($isPackageName) {
            $package = $repository->findOneBy(['name' => $vendorOrPackage]);

            return $package ? [$package] : [];
        }

        return $repository->findBy([
            'vendor' => $vendorOrPackage
        ]);
    }

    /**
     * @param Package[] $packages
     * @param User[] $maintainers
     */
    private function outputPackageTable(OutputInterface $output, array $packages, array $maintainers): void
    {
        $rows = [];

        usort($packages, fn (Package $a, Package $b) => strcasecmp($a->getName(), $b->getName()));

        $newMaintainers = array_map(fn (User $user) => $user->getUsername(), $maintainers);

        foreach ($packages as $package) {
            $currentMaintainers = $package->getMaintainers()->map(fn (User $user) => $user->getUsername())->toArray();
            sort($currentMaintainers);

            $rows[] = [
                $package->getVendor(),
                $package->getPackageName(),
                implode(', ', $currentMaintainers),
                implode(', ', $newMaintainers),
            ];
        }

        $table = new Table($output);
        $table
            ->setHeaders(['Vendor', 'Package', 'Current Maintainers', 'New Maintainers'])
            ->setRows($rows)
        ;
        $table->render();
    }

    /**
     * @param Package[] $packages
     * @param User[] $maintainers
     */
    private function transferOwnership(array $packages, array $maintainers): void
    {
        foreach ($packages as $package) {
            $this->packageManager->transferPackage($package, $maintainers, false);
        }

        $this->doctrine->getManager()->flush();
    }
}
