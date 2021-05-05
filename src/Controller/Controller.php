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
    use \App\Util\DoctrineTrait;

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

    /**
     * @param array<Package|array{id: int}> $packages
     * @return array{downloads: array<int, int>, favers: array<int, int>}
     */
    protected function getPackagesMetadata($packages): array
    {
        $downloads = [];
        $favorites = [];

        try {
            $ids = [];

            $search = false;
            foreach ($packages as $package) {
                if ($package instanceof Package) {
                    $ids[] = $package->getId();
                    // fetch one by one to avoid re-fetching the github stars as we already have them on the package object
                    $favorites[$package->getId()] = $this->favoriteManager->getFaverCount($package);
                } elseif (is_array($package)) {
                    $ids[] = $package['id'];
                    // fetch all in one query if we do not have objects
                    $search = true;
                } else {
                    throw new \LogicException('Got invalid package entity');
                }
            }

            $downloads = $this->downloadManager->getPackagesDownloads($ids);
            if ($search) {
                $favorites = $this->favoriteManager->getFaverCounts($ids);
            }
        } catch (\Predis\Connection\ConnectionException $e) {}

        return ['downloads' => $downloads, 'favers' => $favorites];
    }

    /**
     * Initializes the pager for a query.
     *
     * @param \Doctrine\ORM\QueryBuilder $query Query for packages
     * @param int                        $page  Pagenumber to retrieve.
     */
    protected function setupPager($query, $page): Pagerfanta
    {
        $paginator = new Pagerfanta(new QueryAdapter($query, true));
        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage($page);

        return $paginator;
    }
}
