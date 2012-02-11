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

namespace Packagist\WebBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UserRepository extends EntityRepository
{
    public function findUsersMissingApiToken()
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('u')
            ->from('Packagist\WebBundle\Entity\User', 'u')
            ->where('u.apiToken IS NULL or u.apiToken = ?0')
            ->setParameters(array(''));
        return $qb->getQuery()->getResult();
    }
}
