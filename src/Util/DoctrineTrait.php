<?php declare(strict_types=1);

namespace App\Util;

/**
 * Requires a property doctrine or type Doctrine\Persistence\ManagerRegistry to be present
 */
trait DoctrineTrait
{
    protected function getEM(): \Doctrine\ORM\EntityManager
    {
        return $this->doctrine->getManager();
    }
}
