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
use App\Entity\Package;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
abstract class Controller extends AbstractController
{
    use \App\Util\DoctrineTrait;

    protected ManagerRegistry $doctrine;

    /**
     * @required
     */
    public function setDeps(ManagerRegistry $doctrine): void
    {
        $this->doctrine = $doctrine;
    }

    /**
     * @param array<Package|array{id: int}> $packages
     * @return array{downloads: array<int, int>, favers: array<int, int>}
     */
    protected function getPackagesMetadata(FavoriteManager $favMgr, DownloadManager $dlMgr, iterable $packages): array
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
                    $favorites[$package->getId()] = $favMgr->getFaverCount($package);
                } elseif (is_array($package)) {
                    $ids[] = $package['id'];
                    // fetch all in one query if we do not have objects
                    $search = true;
                } else {
                    throw new \LogicException('Got invalid package entity');
                }
            }

            $downloads = $dlMgr->getPackagesDownloads($ids);
            if ($search) {
                $favorites = $favMgr->getFaverCounts($ids);
            }
        } catch (\Predis\Connection\ConnectionException $e) {}

        return ['downloads' => $downloads, 'favers' => $favorites];
    }
}
