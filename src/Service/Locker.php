<?php declare(strict_types=1);

namespace App\Service;

use Doctrine\Persistence\ManagerRegistry;

class Locker
{
    private $doctrine;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    public function lockPackageUpdate(int $packageId, int $timeout = 0)
    {
        $this->getConn()->connect('master');

        return (bool) $this->getConn()->fetchColumn('SELECT GET_LOCK(:id, :timeout)', ['id' => 'package_update_'.$packageId, 'timeout' => $timeout]);
    }

    public function unlockPackageUpdate(int $packageId)
    {
        $this->getConn()->connect('master');

        $this->getConn()->fetchColumn('SELECT RELEASE_LOCK(:id)', ['id' => 'package_update_'.$packageId]);
    }

    public function lockSecurityAdvisory(string $source, int $timeout = 0)
    {
        $this->getConn()->connect('master');

        return (bool) $this->getConn()->fetchColumn('SELECT GET_LOCK(:id, :timeout)', ['id' => 'security_advisory_'.$source, 'timeout' => $timeout]);
    }

    public function unlockSecurityAdvisory(string $source)
    {
        $this->getConn()->connect('master');

        $this->getConn()->fetchColumn('SELECT RELEASE_LOCK(:id)', ['id' => 'security_advisory_'.$source]);
    }

    public function lockCommand(string $command, int $timeout = 0)
    {
        $this->getConn()->connect('master');

        return (bool) $this->getConn()->fetchColumn(
            'SELECT GET_LOCK(:id, :timeout)',
            ['id' => $command, 'timeout' => $timeout]
        );
    }

    public function unlockCommand(string $command)
    {
        $this->getConn()->connect('master');

        $this->getConn()->fetchColumn(
            'SELECT RELEASE_LOCK(:id)',
            ['id' => $command]
        );
    }

    private function getConn()
    {
        return $this->doctrine->getManager()->getConnection();
    }
}
