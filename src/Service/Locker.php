<?php declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Connections\PrimaryReadReplicaConnection;
use Doctrine\Persistence\ManagerRegistry;
use App\Util\DoctrineTrait;

class Locker
{
    use DoctrineTrait;

    public function __construct(private ManagerRegistry $doctrine)
    {
    }

    public function lockPackageUpdate(int $packageId, int $timeout = 0): bool
    {
        $this->connect();

        return (bool) $this->getConn()->fetchOne('SELECT GET_LOCK(:id, :timeout)', ['id' => 'package_update_'.$packageId, 'timeout' => $timeout]);
    }

    public function unlockPackageUpdate(int $packageId): void
    {
        $this->connect();

        $this->getConn()->fetchOne('SELECT RELEASE_LOCK(:id)', ['id' => 'package_update_'.$packageId]);
    }

    public function lockSecurityAdvisory(string $source, int $timeout = 0): bool
    {
        $this->connect();

        return (bool) $this->getConn()->fetchOne('SELECT GET_LOCK(:id, :timeout)', ['id' => 'security_advisory_'.$source, 'timeout' => $timeout]);
    }

    public function unlockSecurityAdvisory(string $source): void
    {
        $this->getConn()->connect();

        $this->getConn()->fetchOne('SELECT RELEASE_LOCK(:id)', ['id' => 'security_advisory_'.$source]);
    }

    public function lockCommand(string $command, int $timeout = 0): bool
    {
        $this->connect();

        return (bool) $this->getConn()->fetchOne(
            'SELECT GET_LOCK(:id, :timeout)',
            ['id' => $command, 'timeout' => $timeout]
        );
    }

    public function unlockCommand(string $command): void
    {
        $this->connect();

        $this->getConn()->fetchOne(
            'SELECT RELEASE_LOCK(:id)',
            ['id' => $command]
        );
    }

    private function connect(): void
    {
        $connection = $this->getConn();
        if ($connection instanceof PrimaryReadReplicaConnection) {
            $connection->connect('primary');
        }
    }

    private function getConn(): Connection
    {
        return $this->getEM()->getConnection();
    }
}
