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

use App\Service\Scheduler;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Package;
use App\Model\ProviderManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UnfreezePackageCommand extends Command
{
    use \App\Util\DoctrineTrait;

    private ProviderManager $providerManager;
    private ManagerRegistry $doctrine;
    private Scheduler $scheduler;

    public function __construct(ProviderManager $providerManager, ManagerRegistry $doctrine, Scheduler $scheduler)
    {
        $this->providerManager = $providerManager;
        $this->doctrine = $doctrine;
        parent::__construct();
        $this->scheduler = $scheduler;
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:unfreeze')
            ->setDefinition([
                new InputArgument('package', InputArgument::REQUIRED, 'Package name to unfreeze'),
            ])
            ->setDescription('Unfreezes a package, marks it for update and clears frozen status')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = $input->getArgument('package');

        $package = $this->getEM()->getRepository(Package::class)->findOneBy(['name' => $name]);
        if ($package === null) {
            $output->writeln('<error>Package '.$name.' not found</error>');
            return 1;
        }

        $this->providerManager->insertPackage($package);
        $package->setCrawledAt(null);
        $package->setUpdatedAt(new \DateTimeImmutable());
        $package->unfreeze();

        $this->getEM()->flush();

        $this->scheduler->scheduleUpdate($package, 'unfreeze cmd', forceDump: true);

        $output->writeln('<info>Package '.$name.' has been unfrozen and marked for update</info>');

        return 0;
    }
}
