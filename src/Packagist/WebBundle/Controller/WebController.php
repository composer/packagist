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

use Packagist\WebBundle\Form\Model\SearchQuery;
use Packagist\WebBundle\Form\Type\SearchQueryType;
use Pagerfanta\Adapter\SolariumAdapter;
use Pagerfanta\Pagerfanta;
use Predis\Connection\ConnectionException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class WebController extends Controller
{
    /**
     * @Template()
     * @Route("/", name="home")
     */
    public function indexAction()
    {
        return array('page' => 'home');
    }

    /**
     * Rendered by views/Web/searchSection.html.twig
     */
    public function searchFormAction(Request $req)
    {
        $form = $this->createForm(SearchQueryType::class, new SearchQuery(), [
            'action' => $this->generateUrl('search.ajax'),
        ]);

        $filteredOrderBys = $this->getFilteredOrderedBys($req);
        $normalizedOrderBys = $this->getNormalizedOrderBys($filteredOrderBys);

        $this->computeSearchQuery($req, $filteredOrderBys);

        $form->handleRequest($req);

        $orderBysViewModel = $this->getOrderBysViewModel($req, $normalizedOrderBys);
        return $this->render('PackagistWebBundle:Web:searchForm.html.twig', array(
            'searchForm' => $form->createView(),
            'orderBys' => $orderBysViewModel
        ));
    }

    /**
     * @Route("/search/", name="search.ajax")
     * @Route("/search.{_format}", requirements={"_format"="(html|json)"}, name="search", defaults={"_format"="html"})
     * @Method({"GET"})
     */
    public function searchAction(Request $req)
    {
        $form = $this->createForm(SearchQueryType::class, new SearchQuery());

        $filteredOrderBys = $this->getFilteredOrderedBys($req);
        $normalizedOrderBys = $this->getNormalizedOrderBys($filteredOrderBys);

        $this->computeSearchQuery($req, $filteredOrderBys);

        $typeFilter = $req->query->get('type');
        $tagsFilter = $req->query->get('tags');

        if ($req->query->has('search_query') || $typeFilter || $tagsFilter) {
            /** @var $solarium \Solarium_Client */
            $solarium = $this->get('solarium.client');
            $select = $solarium->createSelect();

            // configure dismax
            $dismax = $select->getDisMax();
            $dismax->setQueryFields(array('name^4', 'description', 'tags', 'text', 'text_ngram', 'name_split^2'));
            $dismax->setPhraseFields(array('description'));
            $dismax->setBoostFunctions(array('log(trendiness)^10'));
            $dismax->setMinimumMatch(1);
            $dismax->setQueryParser('edismax');

            // filter by type
            if ($typeFilter) {
                $filterQueryTerm = sprintf('type:"%s"', $select->getHelper()->escapeTerm($typeFilter));
                $filterQuery = $select->createFilterQuery('type')->setQuery($filterQueryTerm);
                $select->addFilterQuery($filterQuery);
            }

            // filter by tags
            if ($tagsFilter) {
                $tags = array();
                foreach ((array) $tagsFilter as $tag) {
                    $tags[] = $select->getHelper()->escapeTerm($tag);
                }
                $filterQueryTerm = sprintf('tags:("%s")', implode('" AND "', $tags));
                $filterQuery = $select->createFilterQuery('tags')->setQuery($filterQueryTerm);
                $select->addFilterQuery($filterQuery);
            }

            if (!empty($filteredOrderBys)) {
                $select->addSorts($normalizedOrderBys);
            }

            $form->handleRequest($req);
            if ($form->isValid()) {
                $escapedQuery = $select->getHelper()->escapeTerm($form->getData()->getQuery());
                $escapedQuery = preg_replace('/(^| )\\\\-(\S)/', '$1-$2', $escapedQuery);
                $escapedQuery = preg_replace('/(^| )\\\\\+(\S)/', '$1+$2', $escapedQuery);
                if ((substr_count($escapedQuery, '"') % 2) == 0) {
                    $escapedQuery = str_replace('\\"', '"', $escapedQuery);
                }
                $select->setQuery($escapedQuery);
            }

            $paginator = new Pagerfanta(new SolariumAdapter($solarium, $select));

            $perPage = $req->query->getInt('per_page', 15);
            if ($perPage <= 0 || $perPage > 100) {
                if ($req->getRequestFormat() === 'json') {
                    return JsonResponse::create(array(
                        'status' => 'error',
                        'message' => 'The optional packages per_page parameter must be an integer between 1 and 100 (default: 15)',
                    ), 400)->setCallback($req->query->get('callback'));
                }

                $perPage = max(0, min(100, $perPage));
            }
            $paginator->setMaxPerPage($perPage);

            $paginator->setCurrentPage($req->query->get('page', 1), false, true);

            $metadata = array();

            foreach ($paginator as $package) {
                if (is_numeric($package->id)) {
                    $metadata['downloads'][$package->id] = $package->downloads;
                    $metadata['favers'][$package->id] = $package->favers;
                }
            }

            if ($req->getRequestFormat() === 'json') {
                try {
                    $result = array(
                        'results' => array(),
                        'total' => $paginator->getNbResults(),
                    );
                } catch (\Solarium_Client_HttpException $e) {
                    return JsonResponse::create(array(
                        'status' => 'error',
                        'message' => 'Could not connect to the search server',
                    ), 500)->setCallback($req->query->get('callback'));
                }

                foreach ($paginator as $package) {
                    if (ctype_digit((string) $package->id)) {
                        $url = $this->generateUrl('view_package', array('name' => $package->name), UrlGeneratorInterface::ABSOLUTE_URL);
                    } else {
                        $url = $this->generateUrl('view_providers', array('name' => $package->name), UrlGeneratorInterface::ABSOLUTE_URL);
                    }

                    $row = array(
                        'name' => $package->name,
                        'description' => $package->description ?: '',
                        'url' => $url,
                        'repository' => $package->repository,
                    );
                    if (is_numeric($package->id)) {
                        $row['downloads'] = $metadata['downloads'][$package->id];
                        $row['favers'] = $metadata['favers'][$package->id];
                    } else {
                        $row['virtual'] = true;
                    }
                    $result['results'][] = $row;
                }

                if ($paginator->hasNextPage()) {
                    $params = array(
                        '_format' => 'json',
                        'q' => $form->getData()->getQuery(),
                        'page' => $paginator->getNextPage()
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

                return JsonResponse::create($result)->setCallback($req->query->get('callback'));
            }

            if ($req->isXmlHttpRequest()) {
                try {
                    return $this->render('PackagistWebBundle:Web:search.html.twig', array(
                        'packages' => $paginator,
                        'meta' => $metadata,
                        'noLayout' => true,
                    ));
                } catch (\Twig_Error_Runtime $e) {
                    if (!$e->getPrevious() instanceof \Solarium_Client_HttpException) {
                        throw $e;
                    }
                    return JsonResponse::create(array(
                        'status' => 'error',
                        'message' => 'Could not connect to the search server',
                    ), 500)->setCallback($req->query->get('callback'));
                }
            }

            return $this->render('PackagistWebBundle:Web:search.html.twig', array(
                'packages' => $paginator,
                'meta' => $metadata,
            ));
        } elseif ($req->getRequestFormat() === 'json') {
            return JsonResponse::create(array(
                'error' => 'Missing search query, example: ?q=example'
            ), 400)->setCallback($req->query->get('callback'));
        }

        return $this->render('PackagistWebBundle:Web:search.html.twig');
    }

    /**
     * @Route("/statistics", name="stats")
     * @Template
     */
    public function statsAction()
    {
        $packages = $this->getDoctrine()
            ->getConnection()
            ->fetchAll('SELECT COUNT(*) count, DATE_FORMAT(createdAt, "%Y-%m") month FROM `package` GROUP BY month');

        $versions = $this->getDoctrine()
            ->getConnection()
            ->fetchAll('SELECT COUNT(*) count, DATE_FORMAT(releasedAt, "%Y-%m") month FROM `package_version` GROUP BY month');

        $chart = array('versions' => array(), 'packages' => array(), 'months' => array());

        // prepare x axis
        $date = new \DateTime($packages[0]['month'].'-01');
        $now = new \DateTime;
        while ($date < $now) {
            $chart['months'][] = $month = $date->format('Y-m');
            $date->modify('+1month');
        }

        // prepare data
        $count = 0;
        foreach ($packages as $dataPoint) {
            $count += $dataPoint['count'];
            $chart['packages'][$dataPoint['month']] = $count;
        }

        $count = 0;
        foreach ($versions as $dataPoint) {
            $count += $dataPoint['count'];
            if (in_array($dataPoint['month'], $chart['months'])) {
                $chart['versions'][$dataPoint['month']] = $count;
            }
        }

        // fill gaps at the end of the chart
        if (count($chart['months']) > count($chart['packages'])) {
            $chart['packages'] += array_fill(0, count($chart['months']) - count($chart['packages']), !empty($chart['packages']) ? max($chart['packages']) : 0);
        }
        if (count($chart['months']) > count($chart['versions'])) {
            $chart['versions'] += array_fill(0, count($chart['months']) - count($chart['versions']), !empty($chart['versions']) ? max($chart['versions']) : 0);
        }

        $res = $this->getDoctrine()
            ->getConnection()
            ->fetchAssoc('SELECT DATE_FORMAT(createdAt, "%Y-%m-%d") createdAt FROM `package` ORDER BY id LIMIT 1');
        $downloadsStartDate = $res['createdAt'] > '2012-04-13' ? $res['createdAt'] : '2012-04-13';

        try {
            $redis = $this->get('snc_redis.default');
            $downloads = $redis->get('downloads') ?: 0;

            $date = new \DateTime($downloadsStartDate.' 00:00:00');
            $yesterday = new \DateTime('-2days 00:00:00');
            $dailyGraphStart = new \DateTime('-32days 00:00:00'); // 30 days before yesterday

            $dlChart = $dlChartMonthly = array();
            while ($date <= $yesterday) {
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
     * @param Request $req
     *
     * @return array
     */
    protected function getFilteredOrderedBys(Request $req)
    {
        $orderBys = $req->query->get('orderBys', array());
        if (!$orderBys) {
            $orderBys = $req->query->get('search_query');
            $orderBys = isset($orderBys['orderBys']) ? $orderBys['orderBys'] : array();
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
     * @param array $orderBys
     *
     * @return array
     */
    protected function getNormalizedOrderBys(array $orderBys)
    {
        $normalizedOrderBys = array();

        foreach ($orderBys as $sort) {
            $normalizedOrderBys[$sort['sort']] = $sort['order'];
        }

        return $normalizedOrderBys;
    }

    /**
     * @param Request $req
     * @param array $normalizedOrderBys
     *
     * @return array
     */
    protected function getOrderBysViewModel(Request $req, array $normalizedOrderBys)
    {
        $makeDefaultArrow = function ($sort) use ($normalizedOrderBys) {
            if (isset($normalizedOrderBys[$sort])) {
                if (strtolower($normalizedOrderBys[$sort]) === 'asc') {
                    $val = 'glyphicon-arrow-up';
                } else {
                    $val = 'glyphicon-arrow-down';
                }
            } else {
                $val = '';
            }

            return $val;
        };

        $makeDefaultHref = function ($sort) use ($req, $normalizedOrderBys) {
            if (isset($normalizedOrderBys[$sort])) {
                if (strtolower($normalizedOrderBys[$sort]) === 'asc') {
                    $order = 'desc';
                } else {
                    $order = 'asc';
                }
            } else {
                $order = 'desc';
            }

            $query = $req->query->get('search_query');
            $query = isset($query['query']) ? $query['query'] : '';

            return '?' . http_build_query(array(
                'q' => $query,
                'orderBys' => array(
                    array(
                        'sort' => $sort,
                        'order' => $order
                    )
                )
            ));
        };

        return array(
            'downloads' => array(
                'title' => 'Sort by downloads',
                'class' => 'glyphicon-arrow-down',
                'arrowClass' => $makeDefaultArrow('downloads'),
                'href' => $makeDefaultHref('downloads')
            ),
            'favers' => array(
                'title' => 'Sort by favorites',
                'class' => 'glyphicon-star',
                'arrowClass' => $makeDefaultArrow('favers'),
                'href' => $makeDefaultHref('favers')
            ),
        );
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
