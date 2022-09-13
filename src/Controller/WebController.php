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

use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;
use App\Entity\Version;
use App\Entity\Package;
use App\Entity\PhpStat;
use App\Search\Algolia;
use App\Search\Query;
use App\Util\Killswitch;
use Predis\Connection\ConnectionException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Predis\Client as RedisClient;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class WebController extends Controller
{
    #[Route('/', name: 'home')]
    public function index(Request $req): RedirectResponse|Response
    {
        if ($resp = $this->checkForQueryMatch($req)) {
            return $resp;
        }

        return $this->render('web/index.html.twig');
    }

    #[Route('/search/', name: 'search_web')]
    public function search(Request $req): RedirectResponse|Response
    {
        if ($resp = $this->checkForQueryMatch($req)) {
            return $resp;
        }

        return $this->render('web/search.html.twig');
    }

    #[Route('/search.json', name: 'search_api', methods: 'GET', defaults: ['_format' => 'json'])]
    public function searchApi(Request $req, Algolia $algolia): JsonResponse
    {
        $blockList = ['2400:6180:100:d0::83b:b001', '34.235.38.170'];
        if (in_array($req->getClientIp(), $blockList, true)) {
            return (new JsonResponse([
                'error' => 'Too many requests, reach out to contact@packagist.org',
            ], 400))->setCallback($req->query->get('callback'));
        }

        try {
            $query = new Query(
                $req->query->has('q') ? $req->query->get('q') : $req->query->get('query', ''),
                (array) ($req->query->all()['tags'] ?? []),
                $req->query->get('type', ''),
                $req->query->getInt('per_page', 15),
                $req->query->getInt('page', 1)
            );
        } catch (\InvalidArgumentException $e) {
            return (new JsonResponse([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 400))->setCallback($req->query->get('callback'));
        }

        try {
            $result = $algolia->search($query);
        } catch (AlgoliaException) {
            return (new JsonResponse([
                'status' => 'error',
                'message' => 'Could not connect to the search server',
            ], 500))->setCallback($req->query->get('callback'));
        }

        $response = (new JsonResponse($result))->setCallback($req->query->get('callback'));
        $response->setSharedMaxAge(300);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    #[Route('/statistics', name: 'stats', methods: 'GET')]
    public function statsAction(RedisClient $redis)
    {
        if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }

        $packages = $this->getEM()->getRepository(Package::class)->getCountByYearMonth();
        $versions = $this->getEM()->getRepository(Version::class)->getCountByYearMonth();

        $chart = ['versions' => [], 'packages' => [], 'months' => []];

        // prepare x axis
        $date = new \DateTime($packages[0]['year'] . '-' . $packages[0]['month'] . '-01');
        $now = new \DateTime;
        while ($date < $now) {
            $chart['months'][] = $month = $date->format('Y-m');
            $date->modify('+1month');
        }

        // prepare data
        $count = 0;
        foreach ($packages as $dataPoint) {
            $count += $dataPoint['count'];
            $chart['packages'][$dataPoint['year'] . '-' . str_pad((string) $dataPoint['month'], 2, '0', STR_PAD_LEFT)] = $count;
        }

        $count = 0;
        foreach ($versions as $dataPoint) {
            $yearMonth = $dataPoint['year'] . '-' . str_pad((string) $dataPoint['month'], 2, '0', STR_PAD_LEFT);
            $count += $dataPoint['count'];
            if (in_array($yearMonth, $chart['months'])) {
                $chart['versions'][$yearMonth] = $count;
            }
        }

        // fill gaps at the end of the chart
        if (count($chart['months']) > count($chart['packages'])) {
            $chart['packages'] += array_fill(0, count($chart['months']) - count($chart['packages']), !empty($chart['packages']) ? max($chart['packages']) : 0);
        }
        if (count($chart['months']) > count($chart['versions'])) {
            $chart['versions'] += array_fill(0, count($chart['months']) - count($chart['versions']), !empty($chart['versions']) ? max($chart['versions']) : 0);
        }

        $downloadsStartDate = '2012-04-13';

        try {
            $downloads = $redis->get('downloads') ?: 0;

            $date = new \DateTime($downloadsStartDate.' 00:00:00');
            $today = new \DateTime('today 00:00:00');
            $dailyGraphStart = new \DateTime('-30days 00:00:00'); // 30 days before today

            $dlChart = $dlChartMonthly = [];
            while ($date <= $today) {
                if ($date > $dailyGraphStart) {
                    $dlChart[$date->format('Y-m-d')] = 'downloads:'.$date->format('Ymd');
                }
                $dlChartMonthly[$date->format('Y-m')] = 'downloads:'.$date->format('Ym');
                $date->modify('+1day');
            }

            $dlChart = [
                'labels' => array_keys($dlChart),
                'values' => $redis->mget(array_values($dlChart)),
            ];
            $dlChartMonthly = [
                'labels' => array_keys($dlChartMonthly),
                'values' => $redis->mget(array_values($dlChartMonthly)),
            ];
        } catch (ConnectionException $e) {
            $downloads = 'N/A';
            $dlChart = $dlChartMonthly = null;
        }

        return $this->render('web/stats.html.twig', [
            'chart' => $chart,
            'packages' => !empty($chart['packages']) ? max($chart['packages']) : 0,
            'versions' => !empty($chart['versions']) ? max($chart['versions']) : 0,
            'downloads' => $downloads,
            'downloadsChart' => $dlChart,
            'maxDailyDownloads' => !empty($dlChart) ? max($dlChart['values']) : null,
            'downloadsChartMonthly' => $dlChartMonthly,
            'maxMonthlyDownloads' => !empty($dlChartMonthly) ? max($dlChartMonthly['values']) : null,
            'downloadsStartDate' => $downloadsStartDate,
        ]);
    }

    #[Route('/php-statistics', name: 'php_stats', methods: 'GET')]
    public function phpStatsAction(): Response
    {
        if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }

        $versions = [
            '5.3',
            '5.4',
            '5.5',
            '5.6',
            '7.0',
            '7.1',
            '7.2',
            '7.3',
            '7.4',
            '8.0',
            '8.1',
            '8.2',
            '8.3',
            '8.4',
            // 'hhvm', // honorable mention here but excluded as it's so low (below 0.00%) it's irrelevant
        ];

        $dailyData = $this->getEM()->getRepository(PhpStat::class)->getGlobalChartData($versions, 'days', 'php');
        $monthlyData = $this->getEM()->getRepository(PhpStat::class)->getGlobalChartData($versions, 'months', 'php');

        $resp = $this->render('web/php_stats.html.twig', [
            'dailyData' => $dailyData,
            'monthlyData' => $monthlyData,
        ]);
        $resp->setSharedMaxAge(1800);
        $resp->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $resp;
    }

    #[Route('/statistics.json', name: 'stats_json', defaults: ['_format' => 'json'], methods: 'GET')]
    public function statsTotalsAction(RedisClient $redis)
    {
        if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }

        $downloads = (int) ($redis->get('downloads') ?: 0);
        $packages = $this->getEM()->getRepository(Package::class)->getTotal();
        $versions = $this->getEM()->getRepository(Version::class)->getTotal();

        $totals = [
            'downloads' => $downloads,
            'packages' => $packages,
            'versions' => $versions,
        ];

        return new JsonResponse(['totals' => $totals], 200);
    }

    private function checkForQueryMatch(Request $req): RedirectResponse|null
    {
        $q = $req->query->has('q') ? $req->query->get('q') : $req->query->get('query');

        if (null === $q) {
            return null;
        }

        $package = $this->doctrine->getRepository(Package::class)->findOneBy(['name' => $q]);
        if (null === $package) {
            return null;
        }

        return $this->redirectToRoute('view_package', ['name' => $package->getName()]);
    }
}
