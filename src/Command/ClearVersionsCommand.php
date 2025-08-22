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
use App\Entity\Version;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ClearVersionsCommand extends Command
{
    use \App\Util\DoctrineTrait;

    private ManagerRegistry $doctrine;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:clear:versions')
            ->setDefinition([
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force execution, by default it runs in dry-run mode'),
                new InputOption('ids', null, InputOption::VALUE_REQUIRED, 'Version ids (comma separated) to delete'),
                new InputArgument('package', InputArgument::OPTIONAL, 'Package id to clear versions for'),
            ])
            ->setDescription('Clears all versions from the databases')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $force = $input->getOption('force');
        $versionIds = $input->getOption('ids');

        $versionRepo = $this->getEM()->getRepository(Version::class);

        $packageNames = [];

        if ($versionIds) {
            $ids = explode(',', trim($versionIds, ' ,'));

            while ($ids) {
                $qb = $versionRepo->createQueryBuilder('v');
                $qb->where($qb->expr()->in('v.id', array_splice($ids, 0, 50)));
                $versions = $qb->getQuery()->toIterable();

                foreach ($versions as $version) {
                    $name = $version->getName().' '.$version->getVersion();
                    $output->writeln('Clearing '.$name);
                    if ($force) {
                        $packageNames[] = $version->getName();
                        $versionRepo->remove($version);
                    }
                }

                $this->getEM()->flush();
                $this->getEM()->clear();
                unset($versions);
            }
        } else {
            if ($id = $input->getArgument('package')) {
                $ids = [$id];
            } else {
                $ids = $this->getEM()->getConnection()->fetchFirstColumn('SELECT id FROM package ORDER BY id ASC');
            }

            while ($ids) {
                $qb = $versionRepo->createQueryBuilder('v');
                $qb
                    ->join('v.package', 'p')
                    ->where($qb->expr()->in('p.id', array_splice($ids, 0, 50)));
                $versions = $qb->getQuery()->toIterable();

                foreach ($versions as $version) {
                    $name = $version->getName().' '.$version->getVersion();
                    $output->writeln('Clearing '.$name);
                    if ($force) {
                        $packageNames[] = $version->getName();
                        $versionRepo->remove($version);
                    }
                }

                $this->getEM()->flush();
                $this->getEM()->clear();
                unset($versions);
            }
        }

        if ($force) {
            // mark packages as recently crawled so that they get updated
            $em = $this->getEM();
            $packageRepo = $em->getRepository(Package::class);
            foreach ($packageNames as $name) {
                $package = $packageRepo->findOneBy(['name' => $name]);
                if ($package !== null) {
                    $package->setCrawledAt(new \DateTimeImmutable());
                    $em->persist($package);
                }
            }

            $em->flush();
        }

        return 0;
    }
}
