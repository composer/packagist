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

use App\Entity\Package;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use Pagerfanta\Adapter\FixedAdapter;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Predis\Client as RedisClient;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class ExtensionController extends Controller
{
    #[Route(path: '/extensions.{_format}', name: 'browse_extensions', defaults: ['_format' => 'html'])]
    public function extensionsAction(Request $req, RedisClient $redis, FavoriteManager $favMgr, DownloadManager $dlMgr): Response
    {
        $packageQuery = $this->getEM()
            ->getRepository(Package::class)
            ->createQueryBuilder('p')
            ->where("(p.type = 'php-ext' OR p.type = 'php-ext-zend')")
            ->andWhere('p.frozen IS NULL')
            ->orderBy('p.name');

        $packages = new Pagerfanta(new QueryAdapter($packageQuery, false));
        $packages->setNormalizeOutOfRangePages(true);
        $packages->setMaxPerPage(15);
        $packages->setCurrentPage(max(1, $req->query->getInt('page', 1)));

        $data = [
            'packages' => $packages,
        ];
        $data['meta'] = $this->getPackagesMetadata($favMgr, $dlMgr, $data['packages']);

        if ($req->getRequestFormat() === 'json') {
            $result = [
                'packages' => [],
            ];

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

            $response = new JsonResponse($result);
            $response->setSharedMaxAge(900);
            $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

            return $response;
        }

        return $this->render('extensions/list.html.twig', $data);
    }
}
