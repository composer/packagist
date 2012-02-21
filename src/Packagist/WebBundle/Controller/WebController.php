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
    public function submitPackageAction()
    {
        $package = new Package;
        $package->setEntityRepository($this->getDoctrine()->getRepository('PackagistWebBundle:Package'));
        $form = $this->createForm(new PackageType, $package);

        $request = $this->getRequest();
        if ('POST' === $request->getMethod()) {
            $form->bindRequest($request);
            if ($form->isValid()) {
                try {
                    $user = $this->getUser();
                    $package->addMaintainer($user);
                    $em = $this->getDoctrine()->getEntityManager();
                    $em->persist($package);
                    $em->flush();

                    $this->get('session')->setFlash('success', $package->getName().' has been added to the package list, the repository will be parsed for releases soon.');

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
        $request = $this->getRequest();
        if ('POST' === $request->getMethod()) {
            $form->bindRequest($request);
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
     * @Route("/packages/{name}", name="view_package", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"})
     * @Method({"GET"})
     */
    public function viewPackageAction($name)
    {
        try {
            $package = $this->getDoctrine()
                ->getRepository('PackagistWebBundle:Package')
                ->getFullPackageByName($name);
        } catch (\Doctrine\ORM\NoResultException $e) {
            return $this->redirect($this->generateUrl('search', array('q' => $name, 'reason' => 'package_not_found')));
        }

        $data = array('package' => $package);

        $user = $this->getUser();
        if ($user && $package->getMaintainers()->contains($user)) {
            $data['form'] = $this->createAddMaintainerForm()->createView();
        }

        $data['searchForm'] = $this->createSearchForm()->createView();

        return $data;
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
                ->getFullPackageByName($name);
        } catch (NoResultException $e) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Package not found',)), 404);
        }

        $request = $this->getRequest();

        $username = $request->request->has('username') ?
            $request->request->get('username') :
            $request->query->get('username');

        $apiToken = $request->request->has('apiToken') ?
            $request->request->get('apiToken') :
            $request->query->get('apiToken');

        $update = $request->request->get('update', $request->query->get('update'));
        $autoUpdated = $request->request->get('autoUpdated', $request->query->get('autoUpdated'));

        $user = $doctrine
            ->getRepository('PackagistWebBundle:User')
            ->findOneBy(array('username' => $username, 'apiToken' => $apiToken));

        if (!$user) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Invalid credentials',)), 403);
        }

        if ($package->getMaintainers()->contains($user)) {
            if (null !== $autoUpdated) {
                $package->setAutoUpdated((Boolean) $autoUpdated);
                $doctrine->getEntityManager()->flush();
            }

            if ($update) {
                $updater = new Updater($doctrine);

                $repository = new VcsRepository(array('url' => $package->getRepository()), new NullIO);
                $updater->update($package, $repository);
            }

            return new Response('{"status": "success"}', 202);
        }

        return new Response(json_encode(array('status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)',)), 404);
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
