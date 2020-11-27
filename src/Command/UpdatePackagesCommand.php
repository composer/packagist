<?php

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

use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\IO\ConsoleIO;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Repository\InvalidRepositoryException;
use Composer\Repository\VcsRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Package;
use App\Package\Updater;
use App\Service\Locker;
use App\Service\Scheduler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UpdatePackagesCommand extends Command
{
    private Scheduler $scheduler;
    private Locker $locker;
    private ManagerRegistry $doctrine;

    public function __construct(Scheduler $scheduler, Locker $locker, ManagerRegistry $doctrine)
    {
        $this->scheduler = $scheduler;
        $this->locker = $locker;
        $this->doctrine = $doctrine;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:update')
            ->setDefinition(array(
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a re-crawl of all packages, or if a package name is given forces an update of all versions'),
                new InputOption('delete-before', null, InputOption::VALUE_NONE, 'Force deletion of all versions before an update'),
                new InputOption('update-equal-refs', null, InputOption::VALUE_NONE, 'Force update of all versions even when they already exist'),
                new InputArgument('package', InputArgument::OPTIONAL, 'Package name to update'),
            ))
            ->setDescription('Updates packages')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $verbose = $input->getOption('verbose');
        $force = $input->getOption('force');
        $package = $input->getArgument('package');

        $deleteBefore = false;
        $updateEqualRefs = false;
        $randomTimes = true;

        if (!$this->locker->lockCommand($this->getName())) {
            if ($verbose) {
                $output->writeln('Aborting, another task is running already');
            }
            return 0;
        }

        if ($package) {
            $packages = array(array('id' => $this->doctrine->getRepository(Package::class)->findOneByName($package)->getId()));
            if ($force) {
                $updateEqualRefs = true;
            }
            $randomTimes = false;
        } elseif ($force) {
            $packages = $this->doctrine->getManager()->getConnection()->fetchAll('SELECT id FROM package ORDER BY id ASC');
            $updateEqualRefs = true;
        } else {
            $packages = $this->doctrine->getRepository(Package::class)->getStalePackages();
        }

        $ids = array();
        foreach ($packages as $package) {
            $ids[] = (int) $package['id'];
        }

        if ($input->getOption('delete-before')) {
            $deleteBefore = true;
        }
        if ($input->getOption('update-equal-refs')) {
            $updateEqualRefs = true;
        }

        while ($ids) {
            $idsGroup = array_splice($ids, 0, 100);

            foreach ($idsGroup as $id) {
                $job = $this->scheduler->scheduleUpdate($id, $updateEqualRefs, $deleteBefore, $randomTimes ? new \DateTime('+'.rand(1, 600).'seconds') : null);
                if ($verbose) {
                    $output->writeln('Scheduled update job '.$job->getId().' for package '.$id);
                }

                $this->doctrine->getManager()->clear();
            }
        }

        $this->locker->unlockCommand($this->getName());
    }
}
