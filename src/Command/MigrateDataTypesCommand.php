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

use App\Entity\Dist;
use App\Entity\DistType;
use App\Entity\Version;
use App\Service\FallbackGitHubAuthProvider;
use Composer\Util\HttpDownloader;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Package;
use App\Package\V2Dumper;
use App\Service\Locker;
use Psr\Log\LoggerInterface;
use Seld\Signal\SignalHandler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpClient\Exception\TimeoutException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use ZipArchive;

class MigrateDataTypesCommand extends Command
{
    use \App\Util\DoctrineTrait;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:migrate-db')
            ->setDescription('Migrate DB data types from array to json type, run this command once to migrate old data')
            ->setDefinition([
            ])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        do {
            $em = $this->getEM();
            $versions = $em->getConnection()->fetchAllAssociative('SELECT id, extra FROM package_version WHERE extra LIKE "a%" LIMIT 5000');

            $output->writeln(count($versions).' versions to update');
            $em->getConnection()->beginTransaction();
            foreach ($versions as $version) {
                $extra = unserialize($version['extra']);
                if ($extra === false) {
                    throw new \UnexpectedValueException('Could not parse '.$version['extra']);
                }
                $extra = json_encode($extra);
                if ($extra === false) {
                    throw new \UnexpectedValueException('Failed json-encoding '.$version['extra']);
                }
                $em->getConnection()->update('package_version', ['extra' => $extra], ['id' => $version['id']]);
            }
            $em->getConnection()->commit();
        } while (count($versions) > 0);

        do {
            $emptyRefs = $em->getConnection()->fetchAllAssociative('SELECT package_id, emptyReferences FROM empty_references WHERE emptyReferences LIKE "a%" LIMIT 1000');

            $output->writeln(count($emptyRefs).' empty refs to update');
            $em->getConnection()->beginTransaction();
            foreach ($emptyRefs as $emptyRef) {
                $emptyReferences = unserialize($emptyRef['emptyReferences']);
                if ($emptyReferences === false) {
                    throw new \UnexpectedValueException('Could not parse '.$emptyRef['emptyReferences']);
                }
                $emptyReferences = json_encode($emptyReferences);
                if ($emptyReferences === false) {
                    throw new \UnexpectedValueException('Failed json-encoding '.$emptyRef['emptyReferences']);
                }
                $em->getConnection()->update('empty_references', ['emptyReferences' => $emptyReferences], ['package_id' => $emptyRef['package_id']]);
            }
            $em->getConnection()->commit();
        } while (count($emptyRefs) > 0);

        do {
            $users = $em->getConnection()->fetchAllAssociative('SELECT id, roles FROM fos_user WHERE roles LIKE "a%" LIMIT 1000');

            $output->writeln(count($users).' users to update');
            $em->getConnection()->beginTransaction();
            foreach ($users as $user) {
                $roles = unserialize($user['roles']);
                if ($roles === false) {
                    throw new \UnexpectedValueException('Could not parse '.$user['roles']);
                }
                $roles = json_encode($roles);
                if ($roles === false) {
                    throw new \UnexpectedValueException('Failed json-encoding '.$user['roles']);
                }
                $em->getConnection()->update('fos_user', ['roles' => $roles], ['id' => $user['id']]);
            }
            $em->getConnection()->commit();
        } while (count($users) > 0);

        return 0;
    }
}
