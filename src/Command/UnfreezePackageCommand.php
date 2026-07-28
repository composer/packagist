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
use App\Model\PackageManager;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UnfreezePackageCommand extends Command
{
    use \App\Util\DoctrineTrait;

    public function __construct(
        private ManagerRegistry $doctrine,
        private PackageManager $packageManager,
    ) {
        parent::__construct();
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

        $this->packageManager->unfreeze($package, 'unfreeze cmd');

        $output->writeln('<info>Package '.$name.' has been unfrozen and marked for update</info>');

        return 0;
    }
}
