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

namespace App\Controller;

use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use App\Entity\Package;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Controller extends AbstractController
{
    protected FavoriteManager $favoriteManager;
    protected DownloadManager $downloadManager;
    protected ManagerRegistry $doctrine;

    /**
     * @required
     */
    public function setDeps(FavoriteManager $favMgr, DownloadManager $dlMgr, ManagerRegistry $doctrine)
    {
        $this->favoriteManager = $favMgr;
        $this->downloadManager = $dlMgr;
        $this->doctrine = $doctrine;
    }

    protected function getPackagesMetadata($packages)
    {
        try {
            $ids = array();

            if (!count($packages)) {
                return;
            }

            $favs = array();
            $search = false;
            foreach ($packages as $package) {
                if ($package instanceof Package) {
                    $ids[] = $package->getId();
                    $favs[$package->getId()] = $this->favoriteManager->getFaverCount($package);
                } elseif (is_array($package)) {
                    $search = true;
                    $ids[] = $package['id'];
                } else {
                    throw new \LogicException('Got invalid package entity');
                }
            }

            if ($search) {
                return array(
                    'downloads' => $this->downloadManager->getPackagesDownloads($ids),
                    'favers' => $this->favoriteManager->getFaverCounts($ids),
                );
            }

            return array(
                'downloads' => $this->downloadManager->getPackagesDownloads($ids),
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
        $paginator = new Pagerfanta(new QueryAdapter($query, true));
        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage($page);

        return $paginator;
    }
}
