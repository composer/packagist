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
use Composer\IO\BufferIO;
use Composer\IO\ConsoleIO;
use Composer\Repository\InvalidRepositoryException;

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
                new InputOption('notify-failures', null, InputOption::VALUE_NONE, 'Notify failures to maintainers by email'),
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
        $router = $this->getContainer()->get('router');

        $flags = 0;

        if ($package) {
            $packages = array(array('id' => $doctrine->getRepository('PackagistWebBundle:Package')->findOneByName($package)->getId()));
            $flags = Updater::UPDATE_EQUAL_REFS;
        } elseif ($force) {
            $packages = $doctrine->getManager()->getConnection()->fetchAll('SELECT id FROM package ORDER BY id ASC');
            $flags = Updater::UPDATE_EQUAL_REFS;
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

        if ($verbose && $input->getOption('notify-failures')) {
            throw new \LogicException('Failures can not be notified in verbose mode since the output is piped to the CLI');
        }

        $input->setInteractive(false);
        $config = Factory::createConfig();
        $io = $verbose ? new ConsoleIO($input, $output, $this->getApplication()->getHelperSet()) : new BufferIO('');
        $io->loadConfiguration($config);
        $loader = new ValidatingArrayLoader(new ArrayLoader());

        while ($ids) {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getPackagesWithVersions(array_splice($ids, 0, 50));

            foreach ($packages as $package) {
                if ($verbose) {
                    $output->writeln('Importing '.$package->getRepository());
                }
                try {
                    if (null === $io || $io instanceof BufferIO) {
                        $io = new BufferIO('');
                        $io->loadConfiguration($config);
                    }
                    $repository = new VcsRepository(array('url' => $package->getRepository()), $io, $config);
                    $repository->setLoader($loader);
                    $updater->update($package, $repository, $flags, $start);
                } catch (InvalidRepositoryException $e) {
                    $output->writeln('<error>Broken repository in '.$router->generate('view_package', array('name' => $package->getName()), true).': '.$e->getMessage().'</error>');
                    if ($input->getOption('notify-failures')) {
                        if (!$this->getContainer()->get('packagist.package_manager')->notifyUpdateFailure($package, $e, $io->getOutput())) {
                            $output->writeln('<error>Failed to notify maintainers</error>');
                        }
                    }
                } catch (\Exception $e) {
                    $output->writeln('<error>Error updating '.$router->generate('view_package', array('name' => $package->getName()), true).' ['.get_class($e).']: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine().'</error>');
                }
            }

            $doctrine->getManager()->clear();
            unset($packages);
        }
    }
}
