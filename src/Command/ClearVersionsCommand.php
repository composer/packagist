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

use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Package;
use App\Entity\Version;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ClearVersionsCommand extends Command
{
    private ManagerRegistry $doctrine;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:clear:versions')
            ->setDefinition(array(
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force execution, by default it runs in dry-run mode'),
                new InputOption('ids', null, InputOption::VALUE_REQUIRED, 'Version ids (comma separated) to delete'),
                new InputArgument('package', InputArgument::OPTIONAL, 'Package id to clear versions for'),
            ))
            ->setDescription('Clears all versions from the databases')
            ->setHelp(<<<EOF

EOF
            )
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $force = $input->getOption('force');
        $versionIds = $input->getOption('ids');

        $versionRepo = $this->doctrine->getRepository(Version::class);

        $packageNames = array();

        if ($versionIds) {
            $ids = explode(',', trim($versionIds, ' ,'));

            while ($ids) {
                $qb = $versionRepo->createQueryBuilder('v');
                $qb->where($qb->expr()->in('v.id', array_splice($ids, 0, 50)));
                $versions = $qb->getQuery()->iterate();

                foreach ($versions as $version) {
                    $version = $version[0];
                    $name = $version->getName().' '.$version->getVersion();
                    $output->writeln('Clearing '.$name);
                    if ($force) {
                        $packageNames[] = $version->getName();
                        $versionRepo->remove($version);
                    }
                }

                $this->doctrine->getManager()->flush();
                $this->doctrine->getManager()->clear();
                unset($versions);
            }
        } else {
            if ($id = $input->getArgument('package')) {
                $ids = [$id];
            } else {
                $packages = $this->doctrine->getManager()->getConnection()->fetchAll('SELECT id FROM package ORDER BY id ASC');
                $ids = array();
                foreach ($packages as $package) {
                    $ids[] = $package['id'];
                }
            }

            while ($ids) {
                $qb = $versionRepo->createQueryBuilder('v');
                $qb
                    ->join('v.package', 'p')
                    ->where($qb->expr()->in('p.id', array_splice($ids, 0, 50)));
                $versions = $qb->getQuery()->iterate();

                foreach ($versions as $version) {
                    $version = $version[0];
                    $name = $version->getName().' '.$version->getVersion();
                    $output->writeln('Clearing '.$name);
                    if ($force) {
                        $packageNames[] = $version->getName();
                        $versionRepo->remove($version);
                    }
                }

                $this->doctrine->getManager()->flush();
                $this->doctrine->getManager()->clear();
                unset($versions);
            }
        }

        if ($force) {
            // mark packages as recently crawled so that they get updated
            $packageRepo = $this->doctrine->getRepository(Package::class);
            foreach ($packageNames as $name) {
                $package = $packageRepo->findOneByName($name);
                $package->setCrawledAt(new \DateTime);
            }

            $this->doctrine->getManager()->flush();
        }
    }
}
