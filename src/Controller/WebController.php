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

use Algolia\AlgoliaSearch\SearchClient;
use App\Form\Model\SearchQuery;
use App\Form\Type\SearchQueryType;
use App\Entity\Package;
use Predis\Connection\ConnectionException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Predis\Client as RedisClient;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class WebController extends Controller
{
    /**
     * @Template()
     * @Route("/", name="home")
     */
    public function indexAction(Request $req)
    {
        if ($resp = $this->checkForQueryMatch($req)) {
            return $resp;
        }

        return array('page' => 'home');
    }

    /**
     * Rendered by views/Web/search_section.html.twig
     */
    public function searchFormAction(Request $req)
    {
        $form = $this->createForm(SearchQueryType::class, new SearchQuery(), [
            'action' => $this->generateUrl('search.ajax'),
        ]);

        $filteredOrderBys = $this->getFilteredOrderedBys($req);

        $this->computeSearchQuery($req, $filteredOrderBys);

        $form->handleRequest($req);

        return $this->render('web/search_form.html.twig', array(
            'searchQuery' => $req->query->get('search_query')['query'] ?? '',
        ));
    }

    private function checkForQueryMatch(Request $req)
    {
        $q = $req->query->get('query');
        if ($q) {
            $package = $this->doctrine->getRepository(Package::class)->findOneByName($q);
            if ($package) {
                return $this->redirectToRoute('view_package', ['name' => $package->getName()]);
            }
        }
    }

    /**
     * @Route("/search/", name="search.ajax", methods={"GET"})
     * @Route("/search.{_format}", requirements={"_format"="(html|json)"}, name="search", defaults={"_format"="html"}, methods={"GET"})
     */
    public function searchAction(Request $req, SearchClient $algolia, string $algoliaIndexName)
    {
        if ($resp = $this->checkForQueryMatch($req)) {
            return $resp;
        }

        if ($req->getRequestFormat() !== 'json') {
            return $this->render('web/search.html.twig', [
                'packages' => [],
            ]);
        }

        $blockList = ['2400:6180:100:d0::83b:b001', '34.235.38.170'];
        if (in_array($req->getClientIp(), $blockList, true)) {
            return JsonResponse::create(array(
                'error' => 'Too many requests, reach out to contact@packagist.org'
            ), 400)->setCallback($req->query->get('callback'));
        }

        $typeFilter = str_replace('%type%', '', $req->query->get('type'));
        $tagsFilter = $req->query->get('tags');

        $filteredOrderBys = $this->getFilteredOrderedBys($req);

        $this->computeSearchQuery($req, $filteredOrderBys);

        if (!$req->query->has('search_query') && !$typeFilter && !$tagsFilter) {
            return JsonResponse::create(array(
                'error' => 'Missing search query, example: ?q=example'
            ), 400)->setCallback($req->query->get('callback'));
        }

        $form = $this->createForm(SearchQueryType::class, new SearchQuery());

        $index = $algolia->initIndex($algoliaIndexName);
        $query = '';
        $queryParams = [];

        // filter by type
        if ($typeFilter) {
            $queryParams['filters'][] = 'type:'.$typeFilter;
        }

        // filter by tags
        if ($tagsFilter) {
            $tags = array();
            foreach ((array) $tagsFilter as $tag) {
                $tag = strtr($tag, '-', ' ');
                $tags[] = 'tags:"'.$tag.'"';
                if (false !== strpos($tag, ' ')) {
                    $tags[] = 'tags:"'.strtr($tag, ' ', '-').'"';
                }
            }
            $queryParams['filters'][] = '(' . implode(' OR ', $tags) . ')';
        }

        if (!empty($filteredOrderBys)) {
            return JsonResponse::create(array(
                'status' => 'error',
                'message' => 'Search sorting is not available anymore',
            ), 400)->setCallback($req->query->get('callback'));
        }

        $form->handleRequest($req);
        if ($form->isSubmitted() && $form->isValid()) {
            $query = $form->getData()->getQuery();
        }

        $perPage = max(1, (int) $req->query->getInt('per_page', 15));
        if ($perPage <= 0 || $perPage > 100) {
           if ($req->getRequestFormat() === 'json') {
                return JsonResponse::create(array(
                    'status' => 'error',
                    'message' => 'The optional packages per_page parameter must be an integer between 1 and 100 (default: 15)',
                ), 400)->setCallback($req->query->get('callback'));
            }

            $perPage = max(0, min(100, $perPage));
        }

        if (isset($queryParams['filters'])) {
            $queryParams['filters'] = implode(' AND ', $queryParams['filters']);
        }
        $queryParams['hitsPerPage'] = $perPage;
        $queryParams['page'] = max(1, (int) $req->query->get('page', 1)) - 1;

        try {
            $results = $index->search($query, $queryParams);
        } catch (\Throwable $e) {
            return JsonResponse::create(array(
                'status' => 'error',
                'message' => 'Could not connect to the search server',
            ), 500)->setCallback($req->query->get('callback'));
        }

        $result = array(
            'results' => array(),
            'total' => $results['nbHits'],
        );

        foreach ($results['hits'] as $package) {
            if (ctype_digit((string) $package['id'])) {
                $url = $this->generateUrl('view_package', array('name' => $package['name']), UrlGeneratorInterface::ABSOLUTE_URL);
            } else {
                $url = $this->generateUrl('view_providers', array('name' => $package['name']), UrlGeneratorInterface::ABSOLUTE_URL);
            }

            $row = array(
                'name' => $package['name'],
                'description' => $package['description'] ?: '',
                'url' => $url,
                'repository' => $package['repository'],
            );
            if (ctype_digit((string) $package['id'])) {
                $row['downloads'] = $package['meta']['downloads'];
                $row['favers'] = $package['meta']['favers'];
            } else {
                $row['virtual'] = true;
            }
            if (!empty($package['abandoned'])) {
                $row['abandoned'] = $package['replacementPackage'] ?? true;
            }
            $result['results'][] = $row;
        }

        if ($results['nbPages'] > $results['page'] + 1) {
            $params = array(
                '_format' => 'json',
                'q' => $form->getData()->getQuery(),
                'page' => $results['page'] + 2,
            );
            if ($tagsFilter) {
                $params['tags'] = (array) $tagsFilter;
            }
            if ($typeFilter) {
                $params['type'] = $typeFilter;
            }
            if ($perPage !== 15) {
                $params['per_page'] = $perPage;
            }
            $result['next'] = $this->generateUrl('search', $params, UrlGeneratorInterface::ABSOLUTE_URL);
        }

        $response = JsonResponse::create($result)->setCallback($req->query->get('callback'));
        $response->setSharedMaxAge(300);

        return $response;
    }

    /**
     * @Route("/statistics", name="stats")
     * @Template
     * @Cache(smaxage=5)
     */
    public function statsAction(RedisClient $redis)
    {
        $packages = $this->doctrine
            ->getConnection()
            ->fetchAll('SELECT COUNT(*) count, YEAR(createdAt) year, MONTH(createdAt) month FROM `package` GROUP BY year, month');

        $versions = $this->doctrine
            ->getConnection()
            ->fetchAll('SELECT COUNT(*) count, YEAR(releasedAt) year, MONTH(releasedAt) month FROM `package_version` GROUP BY year, month');

        $chart = array('versions' => array(), 'packages' => array(), 'months' => array());

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
            $chart['packages'][$dataPoint['year'] . '-' . str_pad($dataPoint['month'], 2, '0', STR_PAD_LEFT)] = $count;
        }

        $count = 0;
        foreach ($versions as $dataPoint) {
            $yearMonth = $dataPoint['year'] . '-' . str_pad($dataPoint['month'], 2, '0', STR_PAD_LEFT);
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

            $dlChart = $dlChartMonthly = array();
            while ($date <= $today) {
                if ($date > $dailyGraphStart) {
                    $dlChart[$date->format('Y-m-d')] = 'downloads:'.$date->format('Ymd');
                }
                $dlChartMonthly[$date->format('Y-m')] = 'downloads:'.$date->format('Ym');
                $date->modify('+1day');
            }

            $dlChart = array(
                'labels' => array_keys($dlChart),
                'values' => $redis->mget(array_values($dlChart))
            );
            $dlChartMonthly = array(
                'labels' => array_keys($dlChartMonthly),
                'values' => $redis->mget(array_values($dlChartMonthly))
            );
        } catch (ConnectionException $e) {
            $downloads = 'N/A';
            $dlChart = $dlChartMonthly = null;
        }

        return array(
            'chart' => $chart,
            'packages' => !empty($chart['packages']) ? max($chart['packages']) : 0,
            'versions' => !empty($chart['versions']) ? max($chart['versions']) : 0,
            'downloads' => $downloads,
            'downloadsChart' => $dlChart,
            'maxDailyDownloads' => !empty($dlChart) ? max($dlChart['values']) : null,
            'downloadsChartMonthly' => $dlChartMonthly,
            'maxMonthlyDownloads' => !empty($dlChartMonthly) ? max($dlChartMonthly['values']) : null,
            'downloadsStartDate' => $downloadsStartDate,
        );
    }

    /**
     * @Route("/statistics.json", name="stats_json", defaults={"_format"="json"}, methods={"GET"})
     */
    public function statsTotalsAction(RedisClient $redis)
    {
        $downloads = (int) ($redis->get('downloads') ?: 0);
        $packages = (int) $this->doctrine
            ->getConnection()
            ->fetchColumn('SELECT COUNT(*) count FROM `package`');

        $versions = (int) $this->doctrine
            ->getConnection()
            ->fetchColumn('SELECT COUNT(*) count FROM `package_version`');

        $totals = [
            'downloads' => $downloads,
            'packages' => $packages,
            'versions' => $versions,
        ];

        return new JsonResponse(['totals' => $totals], 200);
    }

    /**
     * @param Request $req
     *
     * @return array
     */
    protected function getFilteredOrderedBys(Request $req)
    {
        $orderBys = $req->query->get('orderBys', array());
        if (!$orderBys) {
            $orderBys = $req->query->get('search_query');
            $orderBys = $orderBys['orderBys'] ?? array();
        }

        if ($orderBys) {
            $allowedSorts = array(
                'downloads' => 1,
                'favers' => 1
            );

            $allowedOrders = array(
                'asc' => 1,
                'desc' => 1,
            );

            $filteredOrderBys = array();

            foreach ($orderBys as $orderBy) {
                if (isset($orderBy['sort'])
                    && isset($allowedSorts[$orderBy['sort']])
                    && isset($orderBy['order'])
                    && isset($allowedOrders[$orderBy['order']])) {
                    $filteredOrderBys[] = $orderBy;
                }
            }
        } else {
            $filteredOrderBys = array();
        }

        return $filteredOrderBys;
    }

    /**
     * @param Request $req
     * @param array $filteredOrderBys
     */
    private function computeSearchQuery(Request $req, array $filteredOrderBys)
    {
        // transform q=search shortcut
        if ($req->query->has('q') || $req->query->has('orderBys')) {
            $searchQuery = array();

            $q = $req->query->get('q');

            if ($q !== null) {
                $searchQuery['query'] = $q;
            }

            if (!empty($filteredOrderBys)) {
                $searchQuery['orderBys'] = $filteredOrderBys;
            }

            $req->query->set(
                'search_query',
                $searchQuery
            );
        }
    }
}
