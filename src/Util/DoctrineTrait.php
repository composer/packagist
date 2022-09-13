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
