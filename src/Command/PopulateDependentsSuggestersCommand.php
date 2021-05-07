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

use App\Entity\Dependent;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Entity\Download;
use App\Service\Locker;
use Predis\Client;
use Symfony\Component\Console\Command\Command;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PopulateDependentsSuggestersCommand extends Command
{
    use \App\Util\DoctrineTrait;

    private Locker $locker;
    private ManagerRegistry $doctrine;

    public function __construct(Locker $locker, ManagerRegistry $doctrine)
    {
        $this->locker = $locker;
        $this->doctrine = $doctrine;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('packagist:populate:dependents')
            ->setDefinition([])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $conn = $this->getEM()->getConnection();

        // fetch existing ids
        $ids = $conn->fetchFirstColumn('SELECT id FROM package ORDER BY id ASC');
        /** @var list<int> $ids */
        $ids = array_map('intval', $ids);

        while ($id = array_shift($ids)) {
            if (!$this->locker->lockPackageUpdate($id)) {
                $ids[] = $id;
                continue;
            }

            try {
                $defaultVersionId = $conn->fetchOne('SELECT id FROM package_version WHERE package_id = :id ORDER BY defaultBranch DESC, releasedAt DESC LIMIT 1', ['id' => $id]);
                if (!$defaultVersionId) {
                    continue;
                }
                $this->getEM()->getRepository(Dependent::class)->updateDependentSuggesters($id, $defaultVersionId);
            } finally {
                $this->locker->unlockPackageUpdate($id);
            }
        }

        return 0;
    }
}
