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

namespace Packagist\WebBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller as BaseController;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Pagerfanta;
use Packagist\WebBundle\Entity\Package;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Controller extends BaseController
{
    protected function getPackagesMetadata($packages)
    {
        $favMgr = $this->get('packagist.favorite_manager');
        $dlMgr = $this->get('packagist.download_manager');

        try {
            $ids = array();

            if (!count($packages)) {
                return;
            }

            $favs = array();
            $solarium = false;
            foreach ($packages as $package) {
                if ($package instanceof \Solarium_Document_ReadOnly) {
                    $solarium = true;
                    $ids[] = $package->id;
                } elseif ($package instanceof Package) {
                    $ids[] = $package->getId();
                    $favs[$package->getId()] = $favMgr->getFaverCount($package);
                } elseif (is_array($package)) {
                    $solarium = true;
                    $ids[] = $package['id'];
                } else {
                    throw new \LogicException('Got invalid package entity');
                }
            }

            if ($solarium) {
                return array(
                    'downloads' => $dlMgr->getPackagesDownloads($ids),
                    'favers' => $favMgr->getFaverCounts($ids),
                );
            }

            return array(
                'downloads' => $dlMgr->getPackagesDownloads($ids),
                'favers' => $favs,
            );
        } catch (\Predis\Connection\ConnectionException $e) {}
    }

    /**
     * Initializes the pager for a query.
     *
     * @param \Doctrine\ORM\QueryBuilder $query Query for packages
     * @param int                        $page  Pagenumber to retrieve.
     * @return \Pagerfanta\Pagerfanta
     */
    protected function setupPager($query, $page)
    {
        $paginator = new Pagerfanta(new DoctrineORMAdapter($query, true));
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage($page, false, true);

        return $paginator;
    }
}
