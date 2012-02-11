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
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Finder\Finder;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Entity\Tag;
use Packagist\WebBundle\Entity\Author;
use Packagist\WebBundle\Package\Updater;
use Packagist\WebBundle\Repository\Repository\RepositoryInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\VcsRepository;
use Composer\Repository\RepositoryManager;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UpdatePackagesCommand extends ContainerAwareCommand
{
    protected $versionParser;

    protected $supportedLinkTypes = array(
        'require'   => 'RequireLink',
        'conflict'  => 'ConflictLink',
        'provide'   => 'ProvideLink',
        'replace'   => 'ReplaceLink',
        'recommend' => 'RecommendLink',
        'suggest'   => 'SuggestLink',
    );

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:update')
            ->setDefinition(array(
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a re-crawl of all packages'),
                new InputArgument('package', InputArgument::OPTIONAL, 'Package name to update (implicitly enables --force)'),
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
        $logger = $this->getContainer()->get('logger');

        $this->versionParser = new VersionParser;

        if ($package) {
            $packages = array($doctrine->getRepository('PackagistWebBundle:Package')->findOneByName($package));
        } elseif ($force) {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->findAll();
        } else {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getStalePackages();
        }

        $start = new \DateTime();
        $updater = new Updater();

        foreach ($packages as $package) {
            if ($verbose) {
                $output->writeln('Importing '.$package->getRepository());
            }
            try {
                $updater->update($doctrine, $package, $start, $force);
            } catch (\Exception $e) {
                $output->writeln('<error>Exception: '.$e->getMessage().', skipping package '.$package->getName().'.</error>');
            }
        }
    }
}
