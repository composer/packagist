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

use Composer\IO\NullIO;
use Composer\Repository\VcsRepository;
use Doctrine\ORM\NoResultException;
use Packagist\WebBundle\Form\Type\AddMaintainerRequestType;
use Packagist\WebBundle\Form\Model\AddMaintainerRequest;
use Packagist\WebBundle\Form\Type\SearchQueryType;
use Packagist\WebBundle\Form\Model\SearchQuery;
use Packagist\WebBundle\Package\Updater;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Form\Type\PackageType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Pagerfanta\Pagerfanta;
use Pagerfanta\Adapter\DoctrineORMAdapter;
use Pagerfanta\Adapter\SolariumAdapter;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class WebController extends Controller
{
    protected function getUser()
    {
        return $user = $this->get('security.context')->getToken()->getUser();
    }

    /**
     * @Template()
     * @Route("/", name="home")
     */
    public function indexAction()
    {
        return array('page' => 'home', 'searchForm' => $this->createSearchForm()->createView());
    }

    /**
     * @Route("/packages/", name="browse")
     */
    public function browseAction(Request $req)
    {
        if ($tag = $req->query->get('tag')) {
            $packages = $this->getDoctrine()
                ->getRepository('PackagistWebBundle:Package')
                ->findByTag($tag);

            $paginator = new Pagerfanta(new DoctrineORMAdapter($packages, true));
            $paginator->setMaxPerPage(15);
            $paginator->setCurrentPage($req->query->get('page', 1), false, true);

            return $this->render('PackagistWebBundle:Web:tag.html.twig', array('packages' => $paginator, 'tag' => $tag));
        }

        $packages = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:Package')
            ->getBaseQueryBuilder();

        $paginator = new Pagerfanta(new DoctrineORMAdapter($packages, true));
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage($req->query->get('page', 1), false, true);

        return $this->render('PackagistWebBundle:Web:browse.html.twig', array('packages' => $paginator));
    }

    /**
     * @Route("/search/", name="search")
     */
    public function searchAction(Request $req)
    {
        $form = $this->createSearchForm();

        // transform q=search shortcut
        if ($req->query->has('q')) {
            $req->query->set('search_query', array('query' => $req->query->get('q')));
        }

        if ($req->query->has('search_query')) {
            $form->bindRequest($req);
            if ($form->isValid()) {
                $solarium = $this->get('solarium.client');

                $select = $solarium->createSelect();

                $escapedQuery = $select->getHelper()->escapePhrase($form->getData()->getQuery());

                $dismax = $select->getDisMax();
                $dismax->setQueryFields(array('name', 'description', 'tags', 'text', 'text_ngram', 'name_split'));
                $dismax->setBoostQuery('name:'.$escapedQuery.'^2 name_split:'.$escapedQuery.'^1.5');
                $dismax->setQueryParser('edismax');
                $select->setQuery($escapedQuery);

                $paginator = new Pagerfanta(new SolariumAdapter($solarium, $select));
                $paginator->setMaxPerPage(15);
                $paginator->setCurrentPage($req->query->get('page', 1), false, true);

                if ($req->isXmlHttpRequest()) {
                    return $this->render('PackagistWebBundle:Web:list.html.twig', array(
                        'packages' => $paginator,
                        'noLayout' => true,
                    ));
                }

                return $this->render('PackagistWebBundle:Web:search.html.twig', array('packages' => $paginator, 'searchForm' => $form->createView()));
            }
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
        $form = $this->createForm(new PackageType, $package);

        if ('POST' === $req->getMethod()) {
            $form->bindRequest($req);
            if ($form->isValid()) {
                try {
                    $user = $this->getUser();
                    $package->addMaintainer($user);
                    $em = $this->getDoctrine()->getEntityManager();
                    $em->persist($package);
                    $em->flush();

                    $this->get('session')->setFlash('success', $package->getName().' has been added to the package list, the repository will now be crawled.');

                    return new RedirectResponse($this->generateUrl('view_package', array('name' => $package->getName())));
                } catch (\Exception $e) {
                    $this->get('logger')->crit($e->getMessage(), array('exception', $e));
                    $this->get('session')->setFlash('error', $package->getName().' could not be saved.');
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
        $form = $this->createForm(new PackageType, $package);

        $response = array('status' => 'error', 'reason' => 'No data posted.');
        $req = $this->getRequest();
        if ('POST' === $req->getMethod()) {
            $form->bindRequest($req);
            if ($form->isValid()) {
                $response = array('status' => 'success', 'name' => $package->getName());
            } else {
                $errors = array();
                foreach ($form->getChildren() as $child) {
                    if ($child->hasErrors()) {
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

        return array('packages' => $packages, 'vendor' => $vendor, 'paginate' => false, 'searchForm' => $this->createSearchForm()->createView());
    }

    /**
     * @Template()
     * @Route(
     *     "/packages/{name}.{_format}",
     *     name="view_package",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(html|json)"},
     *     defaults={"_format"="html"}
     * )
     * @Method({"GET"})
     */
    public function viewPackageAction(Request $req, $name)
    {
        try {
            $package = $this->getDoctrine()
                ->getRepository('PackagistWebBundle:Package')
                ->getFullPackageByName($name);
        } catch (NoResultException $e) {
            return $this->redirect($this->generateUrl('search', array('q' => $name, 'reason' => 'package_not_found')));
        }

        if ('json' === $req->getRequestFormat()) {
            return new Response(json_encode(array('package' => $package->toArray())), 200);
        }

        $data = array('package' => $package);

        $id = $package->getId();

        try {
            $redis = $this->get('snc_redis.default');
            $counts = $redis->mget('dl:'.$id, 'dl:'.$id.':'.date('Ym'), 'dl:'.$id.':'.date('Ymd'));
            $data['downloads'] = array(
                'total' => $counts[0] ?: 0,
                'monthly' => $counts[1] ?: 0,
                'daily' => $counts[2] ?: 0,
            );
        } catch (\Exception $e) {
            $data['downloads'] = array(
                'total' => 'N/A',
                'monthly' => 'N/A',
                'daily' => 'N/A',
            );
        }

        $user = $this->getUser();
        if ($user && $package->getMaintainers()->contains($user)) {
            $data['form'] = $this->createAddMaintainerForm()->createView();
        }

        $data['searchForm'] = $this->createSearchForm()->createView();
        if ($deleteForm = $this->createDeletePackageForm()) {
           $data['deleteForm'] = $deleteForm->createView();
        }

        return $data;
    }

    /**
     * @Template()
     * @Route("/packages/{name}", name="update_package", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, defaults={"_format" = "json"})
     * @Method({"PUT"})
     */
    public function updatePackageAction(Request $req, $name)
    {
        $doctrine = $this->getDoctrine();

        try {
            $package = $doctrine
                ->getRepository('PackagistWebBundle:Package')
                ->getFullPackageByName($name);
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
                $doctrine->getEntityManager()->flush();
            }

            if ($update) {
                $updater = new Updater($doctrine);

                $repository = new VcsRepository(array('url' => $package->getRepository()), new NullIO);
                $updater->update($package, $repository, Updater::UPDATE_TAGS);
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
        if (!$this->get('security.context')->isGranted('ROLE_DELETE_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $doctrine = $this->getDoctrine();

        try {
            $package = $doctrine
                ->getRepository('PackagistWebBundle:Package')
                ->findOneByName($name);
        } catch (NoResultException $e) {
            throw new NotFoundHttpException('The requested package, '.$name.', was not found.');
        }

        $form = $this->createDeletePackageForm();
        $form->bind($req->request->get('form'));
        if ($form->isValid()) {
            $versionRepo = $doctrine->getRepository('PackagistWebBundle:Version');
            foreach ($package->getVersions() as $version) {
                $versionRepo->remove($version);
            }

            $em = $doctrine->getEntityManager();
            $em->remove($package);
            $em->flush();

            return new RedirectResponse($this->generateUrl('home'));
        }

        return new Response('Invalid form input', 400);
    }

    protected function createDeletePackageForm()
    {
        if ($this->get('security.context')->isGranted('ROLE_DELETE_PACKAGES')) {
            return $this->createFormBuilder(array())->getForm();
        }
    }

    /**
     * @Template("PackagistWebBundle:Web:viewPackage.html.twig")
     * @Route("/packages/{name}/maintainers/", name="add_maintainer", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9/_.-]+"})
     */
    public function createMaintainerAction(Request $req, $name)
    {
        $package = $this->getDoctrine()
            ->getRepository('PackagistWebBundle:Package')
            ->findOneByName($name);

        if (!$package) {
            throw new NotFoundHttpException('The requested package, '.$name.', was not found.');
        }

        if (!$package->getMaintainers()->contains($this->getUser())) {
            throw new AccessDeniedException('You must be a package\'s maintainer to modify maintainers.');
        }

        $form = $this->createAddMaintainerForm();
        $data = array(
            'package' => $package,
            'form' => $form->createView(),
            'show_maintainer_form' => true,
        );

        if ('POST' === $req->getMethod()) {
            $form->bindRequest($req);
            if ($form->isValid()) {
                try {
                    $em = $this->getDoctrine()->getEntityManager();
                    $user = $form->getData()->getUser();

                    if (!empty($user)) {
                        if (!$package->getMaintainers()->contains($user)) {
                            $package->addMaintainer($user);
                        }

                        $em->persist($package);
                        $em->flush();

                        $this->get('session')->setFlash('success', $user->getUsername().' is now a '.$package->getName().' maintainer.');

                        return new RedirectResponse($this->generateUrl('view_package', array('name' => $package->getName())));
                    }
                    $this->get('session')->setFlash('error', 'The user could not be found.');
                } catch (\Exception $e) {
                    $this->get('logger')->crit($e->getMessage(), array('exception', $e));
                    $this->get('session')->setFlash('error', 'The maintainer could not be added.');
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
        foreach ($packages as $key => $dataPoint) {
            $count += $dataPoint['count'];
            $chart['packages'][$dataPoint['month']] = $count;
        }

        $count = 0;
        foreach ($versions as $key => $dataPoint) {
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

        try {
            $redis = $this->get('snc_redis.default');
            $downloads = $redis->get('downloads') ?: 0;
        } catch (\Exception $e) {
            $downloads = 'N/A';
        }

        return array(
            'chart' => $chart,
            'packages' => max($chart['packages']),
            'versions' => max($chart['versions']),
            'downloads' => $downloads,
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

    private function createAddMaintainerForm()
    {
        $addMaintainerRequest = new AddMaintainerRequest;
        return $this->createForm(new AddMaintainerRequestType, $addMaintainerRequest);
    }

    private function createSearchForm()
    {
        return $this->createForm(new SearchQueryType, new SearchQuery);
    }
}
