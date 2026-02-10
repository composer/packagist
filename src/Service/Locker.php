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

namespace App\Service;

use App\FilterList\FilterLists;
use App\Util\DoctrineTrait;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\Persistence\ManagerRegistry;

class Locker
{
    use DoctrineTrait;

    public function __construct(private ManagerRegistry $doctrine)
    {
    }

    public function lockPackageUpdate(int $packageId, int $timeout = 0): bool
    {
        $this->ensurePrimaryConnection();

        return (bool) $this->getConn()->fetchOne('SELECT GET_LOCK(:id, :timeout)', ['id' => 'package_update_'.$packageId, 'timeout' => $timeout]);
    }

    public function unlockPackageUpdate(int $packageId): void
    {
        $this->ensurePrimaryConnection();

        $this->getConn()->fetchOne('SELECT RELEASE_LOCK(:id)', ['id' => 'package_update_'.$packageId]);
    }

    public function lockSecurityAdvisory(string $processId, int $timeout = 0): bool
    {
        $this->ensurePrimaryConnection();

        return (bool) $this->getConn()->fetchOne('SELECT GET_LOCK(:id, :timeout)', ['id' => 'security_advisory_'.$processId, 'timeout' => $timeout]);
    }

    public function unlockSecurityAdvisory(string $processId): void
    {
        $this->ensurePrimaryConnection();

        $this->getConn()->fetchOne('SELECT RELEASE_LOCK(:id)', ['id' => 'security_advisory_'.$processId]);
    }

    public function lockFitlerList(string $processId, int $timeout = 0): bool
    {
        $this->ensurePrimaryConnection();

        return (bool) $this->getConn()->fetchOne('SELECT GET_LOCK(:id, :timeout)', ['id' => 'filter_list_'.$processId, 'timeout' => $timeout]);
    }

    public function unlockFilterList(string $processId): void
    {
        $this->ensurePrimaryConnection();

        $this->getConn()->fetchOne('SELECT RELEASE_LOCK(:id)', ['id' => 'filter_list_'.$processId]);
    }

    public function lockCommand(string $command, int $timeout = 0): bool
    {
        $this->ensurePrimaryConnection();

        return (bool) $this->getConn()->fetchOne(
            'SELECT GET_LOCK(:id, :timeout)',
            ['id' => $command, 'timeout' => $timeout]
        );
    }

    public function unlockCommand(string $command): void
    {
        $this->ensurePrimaryConnection();

        $this->getConn()->fetchOne(
            'SELECT RELEASE_LOCK(:id)',
            ['id' => $command]
        );
    }

    private function ensurePrimaryConnection(): void
    {
        $connection = $this->getConn();
        if ($connection instanceof PrimaryReadReplicaConnection) {
            $connection->ensureConnectedToPrimary();
        }
    }

    private function getConn(): Connection
    {
        return $this->getEM()->getConnection();
    }
}
