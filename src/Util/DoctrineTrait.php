<?php declare(strict_types=1);

namespace App\Util;

use Doctrine\ORM\EntityManager;

/**
 * Requires a property doctrine or type Doctrine\Persistence\ManagerRegistry to be present
 */
trait DoctrineTrait
{
    protected function getEM(): EntityManager
    {
        /** @var EntityManager $em */
        $em = $this->doctrine->getManager();

        return $em;
    }
}
