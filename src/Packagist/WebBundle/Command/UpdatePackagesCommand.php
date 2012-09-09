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

namespace Packagist\WebBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Packagist\WebBundle\Package\Updater;
use Composer\Repository\VcsRepository;
use Composer\Factory;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\Loader\ArrayLoader;
use Composer\IO\NullIO;
use Composer\IO\ConsoleIO;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UpdatePackagesCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:update')
            ->setDefinition(array(
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a re-crawl of all packages'),
                new InputOption('delete-before', null, InputOption::VALUE_NONE, 'Force deletion of all versions before an update'),
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

        $doctrine = $this->getContainer()->get('doctrine');

        $flags = 0;

        if ($package) {
            $packages = array(array('id' => $doctrine->getRepository('PackagistWebBundle:Package')->findOneByName($package)->getId()));
            $flags = Updater::UPDATE_TAGS;
        } elseif ($force) {
            $packages = $doctrine->getEntityManager()->getConnection()->fetchAll('SELECT id FROM package ORDER BY id ASC');
            $flags = Updater::UPDATE_TAGS;
        } else {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getStalePackages();
        }

        $ids = array();
        foreach ($packages as $package) {
            $ids[] = $package['id'];
        }

        if ($input->getOption('delete-before')) {
            $flags = Updater::DELETE_BEFORE;
        }

        $updater = $this->getContainer()->get('packagist.package_updater');
        $start = new \DateTime();

        $input->setInteractive(false);
        $io = $verbose ? new ConsoleIO($input, $output, $this->getApplication()->getHelperSet()) : new NullIO;
        $config = Factory::createConfig();
        $loader = new ValidatingArrayLoader(new ArrayLoader());

        while ($ids) {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getPackagesWithVersions(array_splice($ids, 0, 50));

            foreach ($packages as $package) {
                if ($verbose) {
                    $output->writeln('Importing '.$package->getRepository());
                }
                try {
                    $repository = new VcsRepository(array('url' => $package->getRepository()), $io, $config);
                    $repository->setLoader($loader);
                    $updater->update($package, $repository, $flags, $start);
                } catch (\Exception $e) {
                    $output->writeln('<error>Exception: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().', skipping package '.$package->getName().'.</error>');
                }
            }

            $doctrine->getEntityManager()->clear();
            unset($packages);
        }
    }
}
