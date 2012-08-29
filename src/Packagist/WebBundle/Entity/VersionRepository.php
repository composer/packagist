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
class VersionRepository extends EntityRepository
{
    protected $supportedLinkTypes = array(
        'require',
        'conflict',
        'provide',
        'replace',
        'devRequire',
        'suggest',
    );

    public function remove(Version $version)
    {
        $em = $this->getEntityManager();
        $version->getPackage()->getVersions()->removeElement($version);

        foreach ($version->getAuthors() as $author) {
            /** @var $author Author */
            $author->getVersions()->removeElement($version);
        }
        $version->getAuthors()->clear();

        foreach ($version->getTags() as $tag) {
            /** @var $tag Tag */
            $tag->getVersions()->removeElement($version);
        }
        $version->getTags()->clear();

        foreach ($this->supportedLinkTypes as $linkType) {
            foreach ($version->{'get'.$linkType}() as $link) {
                $em->remove($link);
            }
            $version->{'get'.$linkType}()->clear();
        }

        $em->remove($version);
    }

    public function getFullVersion($versionId)
    {
        $qb = $this->getEntityManager()->createQueryBuilder();
        $qb->select('v', 't', 'a', 'req', 'devReq', 'sug', 'rep', 'con', 'pro')
            ->from('Packagist\WebBundle\Entity\Version', 'v')
            ->leftJoin('v.tags', 't')
            ->leftJoin('v.authors', 'a')
            ->leftJoin('v.require', 'req')
            ->leftJoin('v.devRequire', 'devReq')
            ->leftJoin('v.suggest', 'sug')
            ->leftJoin('v.replace', 'rep')
            ->leftJoin('v.conflict', 'con')
            ->leftJoin('v.provide', 'pro')
            ->where('v.id = :id')
            ->setParameter('id', $versionId);

        return $qb->getQuery()->getSingleResult();
    }
}
