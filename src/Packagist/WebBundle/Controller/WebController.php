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

use Composer\Console\HtmlOutputFormatter;
use Composer\IO\BufferIO;
use Composer\Factory;
use Composer\Repository\VcsRepository;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\Loader\ArrayLoader;
use Doctrine\ORM\NoResultException;
use Packagist\WebBundle\Form\Type\AddMaintainerRequestType;
use Packagist\WebBundle\Form\Model\MaintainerRequest;
use Packagist\WebBundle\Form\Type\RemoveMaintainerRequestType;
use Packagist\WebBundle\Form\Type\SearchQueryType;
use Packagist\WebBundle\Form\Model\SearchQuery;
use Packagist\WebBundle\Package\Updater;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Pagerfanta\Adapter\FixedAdapter;
use Packagist\WebBundle\Form\Type\PackageType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Console\Output\OutputInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Adapter\SolariumAdapter;
use Predis\Connection\ConnectionException;

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
        return array('page' => 'home', 'searchForm' => $this->createSearchForm()->createView());
    }

    /**
     * @Template("PackagistWebBundle:Web:browse.html.twig")
     * @Route("/packages/", name="allPackages")
     * @Cache(smaxage=900)
     */
    public function allAction(Request $req)
    {
        $filters = array(
            'type' => $req->query->get('type'),
            'tag' => $req->query->get('tag'),
        );

        $data = $filters;
        $page = $req->query->get('page', 1);

        $packages = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:Package')
            ->getFilteredQueryBuilder($filters);

        $data['packages'] = $this->setupPager($packages, $page);
        $data['meta'] = $this->getPackagesMetadata($data['packages']);
        $data['searchForm'] = $this->createSearchForm()->createView();

        return $data;
    }

    /**
     * @Template()
     * @Route("/explore/", name="browse")
     */
    public function exploreAction(Request $req)
    {
        $pkgRepo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');
        $verRepo = $this->getDoctrine()->getRepository('PackagistWebBundle:Version');
        $newSubmitted = $pkgRepo->getQueryBuilderForNewestPackages()->setMaxResults(10)
            ->getQuery()->useResultCache(true, 900, 'new_submitted_packages')->getResult();
        $newReleases = $verRepo->getLatestReleases(10);
        $randomIds = $this->getDoctrine()->getConnection()->fetchAll('SELECT id FROM package ORDER BY RAND() LIMIT 10');
        $random = $pkgRepo->createQueryBuilder('p')->where('p.id IN (:ids)')->setParameter('ids', $randomIds)->getQuery()->getResult();
        try {
            $popular = array();
            $popularIds = $this->get('snc_redis.default')->zrevrange('downloads:trending', 0, 9);
            if ($popularIds) {
                $popular = $pkgRepo->createQueryBuilder('p')->where('p.id IN (:ids)')->setParameter('ids', $popularIds)
                    ->getQuery()->useResultCache(true, 900, 'popular_packages')->getResult();
                usort($popular, function ($a, $b) use ($popularIds) {
                    return array_search($a->getId(), $popularIds) > array_search($b->getId(), $popularIds) ? 1 : -1;
                });
            }
        } catch (ConnectionException $e) {
            $popular = array();
        }

        $data = array(
            'newlySubmitted' => $newSubmitted,
            'newlyReleased' => $newReleases,
            'random' => $random,
            'popular' => $popular,
            'searchForm' => $this->createSearchForm()->createView(),
        );

        return $data;
    }

    /**
     * @Template()
     * @Route("/explore/popular", name="browse_popular")
     * @Cache(smaxage=900)
     */
    public function popularAction(Request $req)
    {
        $redis = $this->get('snc_redis.default');
        $popularIds = $redis->zrevrange(
            'downloads:trending',
            ($req->get('page', 1) - 1) * 15,
            $req->get('page', 1) * 15 - 1
        );
        $popular = $this->getDoctrine()->getRepository('PackagistWebBundle:Package')
            ->createQueryBuilder('p')->where('p.id IN (:ids)')->setParameter('ids', $popularIds)
            ->getQuery()->useResultCache(true, 900, 'popular_packages')->getResult();
        usort($popular, function ($a, $b) use ($popularIds) {
            return array_search($a->getId(), $popularIds) > array_search($b->getId(), $popularIds) ? 1 : -1;
        });

        $packages = new Pagerfanta(new FixedAdapter($redis->zcard('downloads:trending'), $popular));
        $packages->setMaxPerPage(15);
        $packages->setCurrentPage($req->get('page', 1), false, true);

        $data = array(
            'packages' => $packages,
            'searchForm' => $this->createSearchForm()->createView(),
        );
        $data['meta'] = $this->getPackagesMetadata($data['packages']);

        return $data;
    }

    /**
     * @Route("/packages/list.json", name="list", defaults={"_format"="json"})
     * @Method({"GET"})
     * @Cache(smaxage=60)
     */
    public function listAction(Request $req)
    {
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');

        if ($req->query->get('type')) {
            $names = $repo->getPackageNamesByType($req->query->get('type'));
        } elseif ($req->query->get('vendor')) {
            $names = $repo->getPackageNamesByVendor($req->query->get('vendor'));
        } else {
            $names = array_keys($repo->getPackageNames());
        }

        return new JsonResponse(array('packageNames' => $names));
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

    /**
     * @Route("/search/", name="search.ajax")
     * @Route("/search.{_format}", requirements={"_format"="(html|json)"}, name="search", defaults={"_format"="html"})
     */
    public function searchAction(Request $req)
    {
        $form = $this->createSearchForm();

        // transform q=search shortcut
        if ($req->query->has('q')) {
            $req->query->set('search_query', array('query' => $req->query->get('q')));
        }

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
                $filterQueryTerm = sprintf('type:%s', $select->getHelper()->escapeTerm($typeFilter));
                $filterQuery = $select->createFilterQuery('type')->setQuery($filterQueryTerm);
                $select->addFilterQuery($filterQuery);
            }

            // filter by tags
            if ($tagsFilter) {
                $tags = array();
                foreach ((array) $tagsFilter as $tag) {
                    $tags[] = $select->getHelper()->escapeTerm($tag);
                }
                $filterQueryTerm = sprintf('tags:(%s)', implode(' AND ', $tags));
                $filterQuery = $select->createFilterQuery('tags')->setQuery($filterQueryTerm);
                $select->addFilterQuery($filterQuery);
            }

            if ($req->query->has('search_query')) {
                $form->bind($req);
                if ($form->isValid()) {
                    $escapedQuery = $select->getHelper()->escapeTerm($form->getData()->getQuery());
                    $select->setQuery($escapedQuery);
                }
            }

            $paginator = new Pagerfanta(new SolariumAdapter($solarium, $select));

            $perPage = $req->query->getInt('per_page', 15);
            if ($perPage <= 0 || $perPage > 100) {
                if ($req->getRequestFormat() === 'json') {
                    return new JsonResponse(array(
                        'status' => 'error',
                        'message' => 'The optional packages per_page parameter must be an integer between 1 and 100 (default: 15)',
                    ), 400);
                }

                $perPage = max(0, min(100, $perPage));
            }
            $paginator->setMaxPerPage($perPage);

            $paginator->setCurrentPage($req->query->get('page', 1), false, true);

            $metadata = $this->getPackagesMetadata($paginator);

            if ($req->getRequestFormat() === 'json') {
                try {
                    $result = array(
                        'results' => array(),
                        'total' => $paginator->getNbResults(),
                    );
                } catch (\Solarium_Client_HttpException $e) {
                    return new JsonResponse(array(
                        'status' => 'error',
                        'message' => 'Could not connect to the search server',
                    ), 500);
                }

                foreach ($paginator as $package) {
                    $url = $this->generateUrl('view_package', array('name' => $package->name), true);

                    $result['results'][] = array(
                        'name' => $package->name,
                        'description' => $package->description ?: '',
                        'url' => $url,
                        'downloads' => $metadata['downloads'][$package->id],
                        'favers' => $metadata['favers'][$package->id],
                    );
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
                    $result['next'] = $this->generateUrl('search', $params, true);
                }

                return new JsonResponse($result);
            }

            if ($req->isXmlHttpRequest()) {
                try {
                    return $this->render('PackagistWebBundle:Web:list.html.twig', array(
                        'packages' => $paginator,
                        'meta' => $metadata,
                        'noLayout' => true,
                    ));
                } catch (\Twig_Error_Runtime $e) {
                    if (!$e->getPrevious() instanceof \Solarium_Client_HttpException) {
                        throw $e;
                    }
                    return new JsonResponse(array(
                        'status' => 'error',
                        'message' => 'Could not connect to the search server',
                    ), 500);
                }
            }

            return $this->render('PackagistWebBundle:Web:search.html.twig', array(
                'packages' => $paginator,
                'meta' => $metadata,
                'searchForm' => $form->createView(),
            ));
        } elseif ($req->getRequestFormat() === 'json') {
            return new JsonResponse(array('error' => 'Missing search query, example: ?q=example'), 400);
        }

        return $this->render('PackagistWebBundle:Web:search.html.twig', array('searchForm' => $form->createView()));
    }

    /**
     * @Template()
     * @Route("/packages/submit", name="submit")
     */
    public function submitPackageAction(Request $req)
    {
        $package = new Package;
        $package->setEntityRepository($this->getDoctrine()->getRepository('PackagistWebBundle:Package'));
        $package->setRouter($this->get('router'));
        $form = $this->createForm(new PackageType, $package);

        if ('POST' === $req->getMethod()) {
            $form->bind($req);
            if ($form->isValid()) {
                try {
                    $user = $this->getUser();
                    $package->addMaintainer($user);
                    $em = $this->getDoctrine()->getManager();
                    $em->persist($package);
                    $em->flush();

                    $this->get('session')->getFlashBag()->set('success', $package->getName().' has been added to the package list, the repository will now be crawled.');

                    return new RedirectResponse($this->generateUrl('view_package', array('name' => $package->getName())));
                } catch (\Exception $e) {
                    $this->get('logger')->crit($e->getMessage(), array('exception', $e));
                    $this->get('session')->getFlashBag()->set('error', $package->getName().' could not be saved.');
                }
            }
        }

        return array('form' => $form->createView(), 'page' => 'submit', 'searchForm' => $this->createSearchForm()->createView());
    }

    /**
     * @Route("/packages/fetch-info", name="submit.fetch_info", defaults={"_format"="json"})
     */
    public function fetchInfoAction()
    {
        $package = new Package;
        $package->setEntityRepository($this->getDoctrine()->getRepository('PackagistWebBundle:Package'));
        $package->setRouter($this->get('router'));
        $form = $this->createForm(new PackageType, $package);

        $response = array('status' => 'error', 'reason' => 'No data posted.');
        $req = $this->getRequest();
        if ('POST' === $req->getMethod()) {
            $form->bind($req);
            if ($form->isValid()) {
                list($vendor, $name) = explode('/', $package->getName(), 2);

                $existingPackages = $this->getDoctrine()
                    ->getRepository('PackagistWebBundle:Package')
                    ->createQueryBuilder('p')
                    ->where('p.name LIKE ?0')
                    ->setParameters(array('%/'.$name))
                    ->getQuery()
                    ->getResult();

                $similar = array();

                foreach ($existingPackages as $existingPackage) {
                    $similar[] = array(
                        'name' => $existingPackage->getName(),
                        'url' => $this->generateUrl('view_package', array('name' => $existingPackage->getName()), true),
                    );
                }

                $response = array('status' => 'success', 'name' => $package->getName(), 'similar' => $similar);
            } else {
                $errors = array();
                if (count($form->getErrors())) {
                    foreach ($form->getErrors() as $error) {
                        $errors[] = $error->getMessageTemplate();
                    }
                }
                foreach ($form->all() as $child) {
                    if (count($child->getErrors())) {
                        foreach ($child->getErrors() as $error) {
                            $errors[] = $error->getMessageTemplate();
                        }
                    }
                }
                $response = array('status' => 'error', 'reason' => $errors);
            }
        }

        return new Response(json_encode($response));
    }

    /**
     * @Template()
     * @Route("/packages/{vendor}/", name="view_vendor", requirements={"vendor"="[A-Za-z0-9_.-]+"})
     */
    public function viewVendorAction($vendor)
    {
        $packages = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:Package')
            ->createQueryBuilder('p')
            ->where('p.name LIKE ?0')
            ->setParameters(array($vendor.'/%'))
            ->getQuery()
            ->getResult();

        if (!$packages) {
            return $this->redirect($this->generateUrl('search', array('q' => $vendor, 'reason' => 'vendor_not_found')));
        }

        return array(
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($packages),
            'vendor' => $vendor,
            'paginate' => false,
            'searchForm' => $this->createSearchForm()->createView()
        );
    }

    /**
     * @Route(
     *     "/p/{name}.{_format}",
     *     name="view_package_alias",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(json)"},
     *     defaults={"_format"="html"}
     * )
     * @Route(
     *     "/packages/{name}",
     *     name="view_package_alias2",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?/", "_format"="(json)"},
     *     defaults={"_format"="html"}
     * )
     * @Method({"GET"})
     */
    public function viewPackageAliasAction(Request $req, $name)
    {
        return $this->redirect($this->generateUrl('view_package', array('name' => trim($name, '/'), '_format' => $req->getRequestFormat())));
    }

    /**
     * @Template()
     * @Route(
     *     "/packages/{name}.{_format}",
     *     name="view_package",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(json)"},
     *     defaults={"_format"="html"}
     * )
     * @Method({"GET"})
     */
    public function viewPackageAction(Request $req, $name)
    {
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');

        try {
            /** @var $package Package */
            $package = $repo->findOneByName($name);
        } catch (NoResultException $e) {
            if ('json' === $req->getRequestFormat()) {
                return new JsonResponse(array('status' => 'error', 'message' => 'Package not found'), 404);
            }

            if ($providers = $repo->findProviders($name)) {
                return $this->render('PackagistWebBundle:Web:providers.html.twig', array(
                    'name' => $name,
                    'packages' => $providers,
                    'meta' => $this->getPackagesMetadata($providers),
                    'paginate' => false,
                    'searchForm' => $this->createSearchForm()->createView()
                ));
            }

            return $this->redirect($this->generateUrl('search', array('q' => $name, 'reason' => 'package_not_found')));
        }

        if ('json' === $req->getRequestFormat()) {
            $data = $package->toArray();

            try {
                $data['downloads'] = $this->get('packagist.download_manager')->getDownloads($package);
                $data['favers'] = $this->get('packagist.favorite_manager')->getFaverCount($package);
            } catch (ConnectionException $e) {
                $data['downloads'] = null;
                $data['favers'] = null;
            }

            // TODO invalidate cache on update and make the ttl longer
            $response = new Response(json_encode(array('package' => $data)), 200);
            $response->setSharedMaxAge(3600);

            return $response;
        }

        $version = null;
        if (count($package->getVersions())) {
            $versionRepo = $this->getDoctrine()->getRepository('PackagistWebBundle:Version');
            $version = $versionRepo->getFullVersion($package->getVersions()->first()->getId());
        }

        $data = array(
            'package' => $package,
            'version' => $version
        );

        try {
            $data['downloads'] = $this->get('packagist.download_manager')->getDownloads($package);

            if ($this->getUser()) {
                $data['is_favorite'] = $this->get('packagist.favorite_manager')->isMarked($this->getUser(), $package);
            }
        } catch (ConnectionException $e) {
        }

        $data['searchForm'] = $this->createSearchForm()->createView();
        if ($maintainerForm = $this->createAddMaintainerForm($package)) {
            $data['addMaintainerForm'] = $maintainerForm->createView();
        }
        if ($removeMaintainerForm = $this->createRemoveMaintainerForm($package)) {
            $data['removeMaintainerForm'] = $removeMaintainerForm->createView();
        }
        if ($deleteForm = $this->createDeletePackageForm($package)) {
            $data['deleteForm'] = $deleteForm->createView();
        }
        if ($this->getUser() && (
            $this->get('security.context')->isGranted('ROLE_DELETE_PACKAGES')
            || $package->getMaintainers()->contains($this->getUser())
        )) {
            $data['deleteVersionCsrfToken'] = $this->get('form.csrf_provider')->generateCsrfToken('delete_version');
        }

        return $data;
    }

    /**
     * @Template()
     * @Route(
     *     "/versions/{versionId}.{_format}",
     *     name="view_version",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "versionId"="[0-9]+", "_format"="(json)"}
     * )
     * @Method({"GET"})
     */
    public function viewPackageVersionAction(Request $req, $versionId)
    {
        /** @var \Packagist\WebBundle\Entity\VersionRepository $repo  */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Version');

        $html = $this->renderView(
            'PackagistWebBundle:Web:versionDetails.html.twig',
            array('version' => $repo->getFullVersion($versionId))
        );

        return new JsonResponse(array('content' => $html));
    }

    /**
     * @Template()
     * @Route(
     *     "/versions/{versionId}/delete",
     *     name="delete_version",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "versionId"="[0-9]+"}
     * )
     * @Method({"DELETE"})
     */
    public function deletePackageVersionAction(Request $req, $versionId)
    {
        /** @var \Packagist\WebBundle\Entity\VersionRepository $repo  */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Version');

        /** @var Version $version  */
        $version = $repo->getFullVersion($versionId);
        $package = $version->getPackage();

        if (!$package->getMaintainers()->contains($this->getUser()) && !$this->get('security.context')->isGranted('ROLE_DELETE_PACKAGES')) {
            throw new AccessDeniedException;
        }

        if (!$this->get('form.csrf_provider')->isCsrfTokenValid('delete_version', $req->request->get('_token'))) {
            throw new AccessDeniedException;
        }

        $repo->remove($version);
        $this->getDoctrine()->getManager()->flush();
        $this->getDoctrine()->getManager()->clear();

        return new RedirectResponse($this->generateUrl('view_package', array('name' => $package->getName())));
    }

    /**
     * @Template()
     * @Route("/packages/{name}", name="update_package", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, defaults={"_format" = "json"})
     * @Method({"PUT"})
     */
    public function updatePackageAction($name)
    {
        $doctrine = $this->getDoctrine();

        try {
            $package = $doctrine
                ->getRepository('PackagistWebBundle:Package')
                ->getPackageByName($name);
        } catch (NoResultException $e) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Package not found',)), 404);
        }

        $req = $this->getRequest();

        $username = $req->request->has('username') ?
            $req->request->get('username') :
            $req->query->get('username');

        $apiToken = $req->request->has('apiToken') ?
            $req->request->get('apiToken') :
            $req->query->get('apiToken');

        $update = $req->request->get('update', $req->query->get('update'));
        $autoUpdated = $req->request->get('autoUpdated', $req->query->get('autoUpdated'));

        $user = $this->getUser() ?: $doctrine
            ->getRepository('PackagistWebBundle:User')
            ->findOneBy(array('username' => $username, 'apiToken' => $apiToken));

        if (!$user) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Invalid credentials',)), 403);
        }

        if ($package->getMaintainers()->contains($user) || $this->get('security.context')->isGranted('ROLE_UPDATE_PACKAGES')) {
            if (null !== $autoUpdated) {
                $package->setAutoUpdated((Boolean) $autoUpdated);
                $doctrine->getManager()->flush();
            }

            if ($update) {
                set_time_limit(3600);
                $updater = $this->get('packagist.package_updater');

                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE, new HtmlOutputFormatter(Factory::createAdditionalStyles()));
                $config = Factory::createConfig();
                $io->loadConfiguration($config);
                $repository = new VcsRepository(array('url' => $package->getRepository()), $io, $config);
                $loader = new ValidatingArrayLoader(new ArrayLoader());
                $repository->setLoader($loader);

                try {
                    $updater->update($package, $repository, Updater::UPDATE_EQUAL_REFS);
                } catch (\Exception $e) {
                    return new Response(json_encode(array(
                        'status' => 'error',
                        'message' => '['.get_class($e).'] '.$e->getMessage(),
                        'details' => '<pre>'.$io->getOutput().'</pre>'
                    )), 400);
                }
            }

            return new Response('{"status": "success"}', 202);
        }

        return new Response(json_encode(array('status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)',)), 404);
    }

    /**
     * @Template()
     * @Route("/packages/{name}", name="delete_package", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"})
     * @Method({"DELETE"})
     */
    public function deletePackageAction(Request $req, $name)
    {
        $doctrine = $this->getDoctrine();

        try {
            $package = $doctrine
                ->getRepository('PackagistWebBundle:Package')
                ->findOneByName($name);
        } catch (NoResultException $e) {
            throw new NotFoundHttpException('The requested package, '.$name.', was not found.');
        }

        if (!$form = $this->createDeletePackageForm($package)) {
            throw new AccessDeniedException;
        }
        $form->bind($req->request->get('form'));
        if ($form->isValid()) {
            $versionRepo = $doctrine->getRepository('PackagistWebBundle:Version');
            foreach ($package->getVersions() as $version) {
                $versionRepo->remove($version);
            }

            $packageId = $package->getId();
            $em = $doctrine->getManager();
            $em->remove($package);
            $em->flush();

            // attempt solr cleanup
            try {
                $solarium = $this->get('solarium.client');

                $update = $solarium->createUpdate();
                $update->addDeleteById($packageId);
                $update->addCommit();

                $solarium->update($update);
            } catch (\Solarium_Client_HttpException $e) {}

            return new RedirectResponse($this->generateUrl('home'));
        }

        return new Response('Invalid form input', 400);
    }

    /**
     * @Template("PackagistWebBundle:Web:viewPackage.html.twig")
     * @Route("/packages/{name}/maintainers/", name="add_maintainer", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"})
     */
    public function createMaintainerAction(Request $req, $name)
    {
        /** @var $package Package */
        $package = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:Package')
            ->findOneByName($name);

        if (!$package) {
            throw new NotFoundHttpException('The requested package, '.$name.', was not found.');
        }

        if (!$form = $this->createAddMaintainerForm($package)) {
            throw new AccessDeniedException('You must be a package\'s maintainer to modify maintainers.');
        }

        $data = array(
            'package' => $package,
            'addMaintainerForm' => $form->createView(),
            'show_add_maintainer_form' => true,
        );

        if ('POST' === $req->getMethod()) {
            $form->bind($req);
            if ($form->isValid()) {
                try {
                    $em = $this->getDoctrine()->getManager();
                    $user = $form->getData()->getUser();

                    if (!empty($user)) {
                        if (!$package->getMaintainers()->contains($user)) {
                            $package->addMaintainer($user);
                            $this->get('packagist.package_manager')->notifyNewMaintainer($user, $package);
                        }

                        $em->persist($package);
                        $em->flush();

                        $this->get('session')->getFlashBag()->set('success', $user->getUsername().' is now a '.$package->getName().' maintainer.');

                        return new RedirectResponse($this->generateUrl('view_package', array('name' => $package->getName())));
                    }
                    $this->get('session')->getFlashBag()->set('error', 'The user could not be found.');
                } catch (\Exception $e) {
                    $this->get('logger')->crit($e->getMessage(), array('exception', $e));
                    $this->get('session')->getFlashBag()->set('error', 'The maintainer could not be added.');
                }
            }
        }

        $data['searchForm'] = $this->createSearchForm()->createView();
        return $data;
    }

    /**
     * @Template("PackagistWebBundle:Web:viewPackage.html.twig")
     * @Route("/packages/{name}/maintainers/delete", name="remove_maintainer", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"})
     */
    public function removeMaintainerAction(Request $req, $name)
    {
        /** @var $package Package */
        $package = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:Package')
            ->findOneByName($name);

        if (!$package) {
            throw new NotFoundHttpException('The requested package, '.$name.', was not found.');
        }
        if (!$removeMaintainerForm = $this->createRemoveMaintainerForm($package)) {
            throw new AccessDeniedException('You must be a package\'s maintainer to modify maintainers.');
        }

        $data = array(
            'package' => $package,
            'version' => null,
            'removeMaintainerForm' => $removeMaintainerForm->createView(),
            'show_remove_maintainer_form' => true,
        );

        if ('POST' === $req->getMethod()) {
            $removeMaintainerForm->bind($req);
            if ($removeMaintainerForm->isValid()) {
                try {
                    $em = $this->getDoctrine()->getManager();
                    $user = $removeMaintainerForm->getData()->getUser();

                    if (!empty($user)) {
                        if ($package->getMaintainers()->contains($user)) {
                            $package->getMaintainers()->removeElement($user);
                        }

                        $em->persist($package);
                        $em->flush();

                        $this->get('session')->getFlashBag()->set('success', $user->getUsername().' is no longer a '.$package->getName().' maintainer.');

                        return new RedirectResponse($this->generateUrl('view_package', array('name' => $package->getName())));
                    }
                    $this->get('session')->getFlashBag()->set('error', 'The user could not be found.');
                } catch (\Exception $e) {
                    $this->get('logger')->crit($e->getMessage(), array('exception', $e));
                    $this->get('session')->getFlashBag()->set('error', 'The maintainer could not be removed.');
                }
            }
        }

        $data['searchForm'] = $this->createSearchForm()->createView();
        return $data;
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
            $chart['packages'] += array_fill(0, count($chart['months']) - count($chart['packages']), max($chart['packages']));
        }
        if (count($chart['months']) > count($chart['versions'])) {
            $chart['versions'] += array_fill(0, count($chart['months']) - count($chart['versions']), max($chart['versions']));
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
            'packages' => max($chart['packages']),
            'versions' => max($chart['versions']),
            'downloads' => $downloads,
            'downloadsChart' => $dlChart,
            'maxDailyDownloads' => !empty($dlChart) ? max($dlChart['values']) : null,
            'downloadsChartMonthly' => $dlChartMonthly,
            'maxMonthlyDownloads' => !empty($dlChartMonthly) ? max($dlChartMonthly['values']) : null,
            'downloadsStartDate' => $downloadsStartDate,
        );
    }

    /**
     * @Route("/about-composer")
     */
    public function aboutComposerFallbackAction()
    {
        return new RedirectResponse('http://getcomposer.org/', 301);
    }

    public function render($view, array $parameters = array(), Response $response = null)
    {
        if (!isset($parameters['searchForm'])) {
            $parameters['searchForm'] = $this->createSearchForm()->createView();
        }

        return parent::render($view, $parameters, $response);
    }

    private function createAddMaintainerForm($package)
    {
        if (!$user = $this->getUser()) {
            return;
        }

        if ($this->get('security.context')->isGranted('ROLE_EDIT_PACKAGES') || $package->getMaintainers()->contains($user)) {
            $maintainerRequest = new MaintainerRequest;
            return $this->createForm(new AddMaintainerRequestType, $maintainerRequest);
        }
    }

    private function createRemoveMaintainerForm(Package $package)
    {
        if (!($user = $this->getUser()) || 1 == $package->getMaintainers()->count()) {
            return;
        }

        if ($this->get('security.context')->isGranted('ROLE_EDIT_PACKAGES') || $package->getMaintainers()->contains($user)) {
            $maintainerRequest = new MaintainerRequest;
            return $this->createForm(new RemoveMaintainerRequestType(), $maintainerRequest, array('package'=>$package, 'excludeUser'=>$user));
        }
    }

    private function createDeletePackageForm(Package $package)
    {
        if (!$user = $this->getUser()) {
            return;
        }

        // super admins bypass additional checks
        if (!$this->get('security.context')->isGranted('ROLE_DELETE_PACKAGES')) {
            // non maintainers can not delete
            if (!$package->getMaintainers()->contains($user)) {
                return;
            }

            try {
                $downloads = $this->get('packagist.download_manager')->getDownloads($package);
            } catch (ConnectionException $e) {
                return;
            }

            // more than 50 downloads = established package, do not allow deletion by maintainers
            if ($downloads['total'] > 50) {
                return;
            }
        }

        return $this->createFormBuilder(array())->getForm();
    }

    private function createSearchForm()
    {
        return $this->createForm(new SearchQueryType, new SearchQuery);
    }
}
