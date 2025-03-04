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

    #[\Symfony\Contracts\Service\Attribute\Required]
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
        } catch (\Predis\Connection\ConnectionException $e) {
        }

        return ['downloads' => $downloads, 'favers' => $favorites];
    }

    protected function blockAbusers(Request $req): ?JsonResponse
    {
        if (str_contains((string) $req->headers->get('User-Agent'), 'Bytespider; spider-feedback@bytedance.com')) {
            return new JsonResponse("Please respect noindex/nofollow meta tags, and email contact@packagist.org to get unblocked once this is resolved", 429, ['Retry-After' => 31536000]);
        }

        if ($req->getClientIp() === '18.190.1.42') {
            return new JsonResponse("Please use the updatedSince flag to fetch new security advisories, and email contact@packagist.org to get unblocked once this is resolved", 429, ['Retry-After' => 31536000]);
        }

        $abusers = [
            '193.13.144.72',
            '185.167.99.27',
            '82.77.112.123',
            '14.116.239.33', '14.116.239.34', '14.116.239.35', '14.116.239.36', '14.116.239.37',
            '14.22.11.161', '14.22.11.162', '14.22.11.163', '14.22.11.164', '14.22.11.165',
            '216.251.130.74',
            '212.107.30.81', '35.89.149.248',
            '82.180.155.159',
            '212.107.30.81',
            '107.158.141.2',
            '2a02:4780:d:5838::1',
            '82.180.155.159',
        ];
        if (in_array($req->getClientIp(), $abusers, true)) {
            return new JsonResponse("Please use a proper user-agent with contact information or get in touch before abusing the API", 429, ['Retry-After' => 31536000]);
        }

        return null;
    }
}
