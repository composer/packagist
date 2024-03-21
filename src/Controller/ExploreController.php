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

use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use Doctrine\DBAL\ConnectionException;
use App\Entity\Package;
use App\Entity\Version;
use Pagerfanta\Adapter\FixedAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Predis\Client as RedisClient;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

#[Route(path: '/explore')]
class ExploreController extends Controller
{
    #[Route(path: '/', name: 'browse')]
    public function exploreAction(RedisClient $redis): Response
    {
        $pkgRepo = $this->getEM()->getRepository(Package::class);
        $verRepo = $this->getEM()->getRepository(Version::class);
        $newSubmitted = $pkgRepo->getQueryBuilderForNewestPackages()->setMaxResults(10)
            ->getQuery()->enableResultCache(60)->getResult();
        $newReleases = $verRepo->getLatestReleases(10);
        $maxId = (int) $this->getEM()->getConnection()->fetchOne('SELECT max(id) FROM package');
        $random = $pkgRepo
            ->createQueryBuilder('p')->where('p.id >= :randId')->andWhere('p.abandoned = 0')
            ->setParameter('randId', random_int(1, $maxId))->setMaxResults(10)
            ->getQuery()->getResult();
        try {
            $popular = [];
            $popularIds = $redis->zrevrange('downloads:trending', 0, 9);
            if ($popularIds) {
                $popular = $pkgRepo->createQueryBuilder('p')->where('p.id IN (:ids)')->setParameter('ids', $popularIds)
                    ->getQuery()->enableResultCache(900)->getResult();
                usort($popular, static function ($a, $b) use ($popularIds) {
                    return array_search($a->getId(), $popularIds) > array_search($b->getId(), $popularIds) ? 1 : -1;
                });
            }
        } catch (ConnectionException $e) {
            $popular = [];
        }

        return $this->render('explore/explore.html.twig', [
            'newlySubmitted' => $newSubmitted,
            'newlyReleased' => $newReleases,
            'random' => $random,
            'popular' => $popular,
        ]);
    }

    #[Route(path: '/popular.{_format}', name: 'browse_popular', defaults: ['_format' => 'html'])]
    public function popularAction(Request $req, RedisClient $redis, FavoriteManager $favMgr, DownloadManager $dlMgr): Response
    {
        $perPage = $req->query->getInt('per_page', 15);
        try {
            if ($perPage <= 0 || $perPage > 100) {
                if ($req->getRequestFormat() === 'json') {
                    return new JsonResponse([
                        'status' => 'error',
                        'message' => 'The optional packages per_page parameter must be an integer between 1 and 100 (default: 15)',
                    ], 400);
                }
            }
            $perPage = max(1, min(100, $perPage));

            $popularIds = $redis->zrevrange(
                'downloads:trending',
                (max(1, (int) $req->get('page', 1)) - 1) * $perPage,
                max(1, (int) $req->get('page', 1)) * $perPage - 1
            );
            $popular = $this->getEM()->getRepository(Package::class)
                ->createQueryBuilder('p')->where('p.id IN (:ids)')->setParameter('ids', $popularIds)
                ->getQuery()->enableResultCache(900)->getResult();
            usort($popular, static function ($a, $b) use ($popularIds) {
                return array_search($a->getId(), $popularIds) > array_search($b->getId(), $popularIds) ? 1 : -1;
            });

            $packages = new Pagerfanta(new FixedAdapter($redis->zcard('downloads:trending'), $popular));
            $packages->setNormalizeOutOfRangePages(true);
            $packages->setMaxPerPage($perPage);
            $packages->setCurrentPage(max(1, $req->query->getInt('page', 1)));
        } catch (ConnectionException $e) {
            $packages = new Pagerfanta(new FixedAdapter(0, []));
        }

        $data = [
            'packages' => $packages,
        ];
        $data['meta'] = $this->getPackagesMetadata($favMgr, $dlMgr, $data['packages']);

        if ($req->getRequestFormat() === 'json') {
            $result = [
                'packages' => [],
                'total' => $packages->getNbResults(),
            ];

            /** @var Package $package */
            foreach ($packages as $package) {
                $url = $this->generateUrl('view_package', ['name' => $package->getName()], UrlGeneratorInterface::ABSOLUTE_URL);

                $result['packages'][] = [
                    'name' => $package->getName(),
                    'description' => $package->getDescription() ?: '',
                    'url' => $url,
                    'downloads' => $data['meta']['downloads'][$package->getId()],
                    'favers' => $data['meta']['favers'][$package->getId()],
                ];
            }

            if ($packages->hasNextPage()) {
                $params = [
                    '_format' => 'json',
                    'page' => $packages->getNextPage(),
                ];
                if ($perPage !== 15) {
                    $params['per_page'] = $perPage;
                }
                $result['next'] = $this->generateUrl('browse_popular', $params, UrlGeneratorInterface::ABSOLUTE_URL);
            }

            $response = new JsonResponse($result);
            $response->setSharedMaxAge(900);
            $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

            return $response;
        }

        return $this->render('explore/popular.html.twig', $data);
    }
}
