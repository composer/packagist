<?php

namespace Packagist\WebBundle\Controller;

use Composer\Factory;
use Composer\IO\BufferIO;
use Symfony\Component\Console\Output\OutputInterface;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Console\HtmlOutputFormatter;
use Composer\Repository\VcsRepository;
use Doctrine\ORM\NoResultException;
use Packagist\WebBundle\Entity\PackageRepository;
use Packagist\WebBundle\Entity\VersionRepository;
use Packagist\WebBundle\Form\Model\MaintainerRequest;
use Packagist\WebBundle\Form\Type\AbandonedType;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Form\Type\AddMaintainerRequestType;
use Packagist\WebBundle\Form\Type\PackageType;
use Packagist\WebBundle\Form\Type\RemoveMaintainerRequestType;
use Predis\Connection\ConnectionException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Composer\Package\Version\VersionParser;
use DateTimeImmutable;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Pagerfanta\Adapter\FixedAdapter;
use Pagerfanta\Pagerfanta;
use Packagist\WebBundle\Package\Updater;

class PackageController extends Controller
{
    /**
     * @Template("PackagistWebBundle:Package:browse.html.twig")
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

        return $data;
    }

    /**
     * @Route("/packages/list.json", name="list", defaults={"_format"="json"})
     * @Method({"GET"})
     * @Cache(smaxage=300)
     */
    public function listAction(Request $req)
    {
        /** @var PackageRepository $repo */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');
        $fields = (array) $req->query->get('fields', array());
        $fields = array_intersect($fields, array('repository', 'type'));

        if ($fields) {
            $filters = array_filter(array(
                'type' => $req->query->get('type'),
                'vendor' => $req->query->get('vendor'),
            ));

            return new JsonResponse(array('packages' => $repo->getPackagesWithFields($filters, $fields)));
        }

        if ($req->query->get('type')) {
            $names = $repo->getPackageNamesByType($req->query->get('type'));
        } elseif ($req->query->get('vendor')) {
            $names = $repo->getPackageNamesByVendor($req->query->get('vendor'));
        } else {
            $names = $repo->getPackageNames();
        }

        return new JsonResponse(array('packageNames' => $names));
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
        $form = $this->createForm(PackageType::class, $package, [
            'action' => $this->generateUrl('submit'),
        ]);
        $user = $this->getUser();
        $package->addMaintainer($user);

        $form->handleRequest($req);
        if ($form->isValid()) {
            try {
                $em = $this->getDoctrine()->getManager();
                $em->persist($package);
                $em->flush();

                $this->get('session')->getFlashBag()->set('success', $package->getName().' has been added to the package list, the repository will now be crawled.');

                return new RedirectResponse($this->generateUrl('view_package', array('name' => $package->getName())));
            } catch (\Exception $e) {
                $this->get('logger')->critical($e->getMessage(), array('exception', $e));
                $this->get('session')->getFlashBag()->set('error', $package->getName().' could not be saved.');
            }
        }

        return array('form' => $form->createView(), 'page' => 'submit');
    }

    /**
     * @Route("/packages/fetch-info", name="submit.fetch_info", defaults={"_format"="json"})
     */
    public function fetchInfoAction(Request $req)
    {
        $package = new Package;
        $package->setEntityRepository($this->getDoctrine()->getRepository('PackagistWebBundle:Package'));
        $package->setRouter($this->get('router'));
        $form = $this->createForm(new PackageType, $package);
        $user = $this->getUser();
        $package->addMaintainer($user);

        $form->handleRequest($req);
        if ($form->isValid()) {
            list(, $name) = explode('/', $package->getName(), 2);

            $existingPackages = $this->getDoctrine()
                ->getRepository('PackagistWebBundle:Package')
                ->createQueryBuilder('p')
                ->where('p.name LIKE ?0')
                ->setParameters(array('%/'.$name))
                ->getQuery()
                ->getResult();

            $similar = array();

            /** @var Package $existingPackage */
            foreach ($existingPackages as $existingPackage) {
                $similar[] = array(
                    'name' => $existingPackage->getName(),
                    'url' => $this->generateUrl('view_package', array('name' => $existingPackage->getName()), true),
                );
            }

            return new JsonResponse(array('status' => 'success', 'name' => $package->getName(), 'similar' => $similar));
        }

        if ($form->isSubmitted()) {
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

            return new JsonResponse(array('status' => 'error', 'reason' => $errors));
        }

        return new JsonResponse(array('status' => 'error', 'reason' => 'No data posted.'));
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
        );
    }

    /**
     * @Route(
     *     "/p/{name}.{_format}",
     *     name="view_package_alias",
     *     requirements={"name"="[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+?)?", "_format"="(json)"},
     *     defaults={"_format"="html"}
     * )
     * @Route(
     *     "/packages/{name}",
     *     name="view_package_alias2",
     *     requirements={"name"="[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+?)?/"},
     *     defaults={"_format"="html"}
     * )
     * @Method({"GET"})
     */
    public function viewPackageAliasAction(Request $req, $name)
    {
        $format = $req->getRequestFormat();
        if ($format === 'html') {
            $format = null;
        }
        if ($format === 'json' || (!$format && substr($name, -5) === '.json')) {
            throw new NotFoundHttpException('Package not found');
        }
        if (false === strpos(trim($name, '/'), '/')) {
            return $this->redirect($this->generateUrl('view_vendor', array('vendor' => $name, '_format' => $format)));
        }

        return $this->redirect($this->generateUrl('view_package', array('name' => trim($name, '/'), '_format' => $format)));
    }

    /**
     * @Route(
     *     "/providers/{name}",
     *     name="view_providers",
     *     requirements={"name"="[A-Za-z0-9/_.-]+?"},
     *     defaults={"_format"="html"}
     * )
     * @Method({"GET"})
     */
    public function viewProvidersAction($name)
    {
        /** @var PackageRepository $repo */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');
        $providers = $repo->findProviders($name);
        if (!$providers) {
            return $this->redirect($this->generateUrl('search', array('q' => $name, 'reason' => 'package_not_found')));
        }

        try {
            $redis = $this->get('snc_redis.default');
            $trendiness = array();
            foreach ($providers as $package) {
                /** @var Package $package */
                $trendiness[$package->getId()] = (int) $redis->zscore('downloads:trending', $package->getId());
            }
            usort($providers, function ($a, $b) use ($trendiness) {
                if ($trendiness[$a->getId()] === $trendiness[$b->getId()]) {
                    return strcmp($a->getName(), $b->getName());
                }
                return $trendiness[$a->getId()] > $trendiness[$b->getId()] ? -1 : 1;
            });
        } catch (ConnectionException $e) {}

        return $this->render('PackagistWebBundle:Package:providers.html.twig', array(
            'name' => $name,
            'packages' => $providers,
            'meta' => $this->getPackagesMetadata($providers),
            'paginate' => false,
        ));
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
        /** @var PackageRepository $repo */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');

        try {
            /** @var Package $package */
            $package = $repo->findOneByName($name);
        } catch (NoResultException $e) {
            if ('json' === $req->getRequestFormat()) {
                return new JsonResponse(array('status' => 'error', 'message' => 'Package not found'), 404);
            }

            if ($providers = $repo->findProviders($name)) {
                return $this->redirect($this->generateUrl('view_providers', array('name' => $name)));
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
            $response = new JsonResponse(array('package' => $data));
            $response->setSharedMaxAge(3600);

            return $response;
        }

        $version = null;
        $expandedVersion = null;
        $versions = $package->getVersions();
        if (is_object($versions)) {
            $versions = $versions->toArray();
        }

        usort($versions, Package::class.'::sortVersions');

        if (count($versions)) {
            /** @var VersionRepository $versionRepo */
            $versionRepo = $this->getDoctrine()->getRepository('PackagistWebBundle:Version');
            $version = $versionRepo->getFullVersion(reset($versions)->getId());

            $expandedVersion = reset($versions);
            foreach ($versions as $v) {
                if (!$v->isDevelopment()) {
                    $expandedVersion = $v;
                    break;
                }
            }
            $expandedVersion = $versionRepo->getFullVersion($expandedVersion->getId());
        }

        $data = array(
            'package' => $package,
            'version' => $version,
            'versions' => $versions,
            'expandedVersion' => $expandedVersion,
        );

        try {
            $data['downloads'] = $this->get('packagist.download_manager')->getDownloads($package);

            if ($this->getUser()) {
                $data['is_favorite'] = $this->get('packagist.favorite_manager')->isMarked($this->getUser(), $package);
            }
        } catch (ConnectionException $e) {
        }

        $data['dependents'] = $repo->getDependentCount($package->getName());

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
                $this->isGranted('ROLE_DELETE_PACKAGES')
                || $package->getMaintainers()->contains($this->getUser())
            )) {
            $data['deleteVersionCsrfToken'] = $this->get('security.csrf.token_manager')->getToken('delete_version');
        }

        return $data;
    }

    /**
     * @Route(
     *     "/packages/{name}/downloads.{_format}",
     *     name="package_downloads_full",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(json)"}
     * )
     * @Method({"GET"})
     */
    public function viewPackageDownloadsAction(Request $req, $name)
    {
        /** @var PackageRepository $repo */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');

        try {
            /** @var $package Package */
            $package = $repo->findOneByName($name);
        } catch (NoResultException $e) {
            if ('json' === $req->getRequestFormat()) {
                return new JsonResponse(array('status' => 'error', 'message' => 'Package not found'), 404);
            }

            if ($providers = $repo->findProviders($name)) {
                return $this->redirect($this->generateUrl('view_providers', array('name' => $name)));
            }

            return $this->redirect($this->generateUrl('search', array('q' => $name, 'reason' => 'package_not_found')));
        }

        $versions = $package->getVersions();
        $data = array(
            'name' => $package->getName(),
        );

        try {
            $data['downloads']['total'] = $this->get('packagist.download_manager')->getDownloads($package);
            $data['favers'] = $this->get('packagist.favorite_manager')->getFaverCount($package);
        } catch (ConnectionException $e) {
            $data['downloads']['total'] = null;
            $data['favers'] = null;
        }

        foreach ($versions as $version) {
            try {
                $data['downloads']['versions'][$version->getVersion()] = $this->get('packagist.download_manager')->getDownloads($package, $version);
            } catch (ConnectionException $e) {
                $data['downloads']['versions'][$version->getVersion()] = null;
            }
        }

        $response = new Response(json_encode(array('package' => $data)), 200);
        $response->setSharedMaxAge(3600);

        return $response;
    }

    /**
     * @Route(
     *     "/versions/{versionId}.{_format}",
     *     name="view_version",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "versionId"="[0-9]+", "_format"="(json)"}
     * )
     * @Method({"GET"})
     */
    public function viewPackageVersionAction($versionId)
    {
        /** @var VersionRepository $repo  */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Version');

        $html = $this->renderView(
            'PackagistWebBundle:Package:versionDetails.html.twig',
            array('version' => $repo->getFullVersion($versionId))
        );

        return new JsonResponse(array('content' => $html));
    }

    /**
     * @Route(
     *     "/versions/{versionId}/delete",
     *     name="delete_version",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "versionId"="[0-9]+"}
     * )
     * @Method({"DELETE"})
     */
    public function deletePackageVersionAction(Request $req, $versionId)
    {
        /** @var VersionRepository $repo  */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Version');

        /** @var Version $version  */
        $version = $repo->getFullVersion($versionId);
        $package = $version->getPackage();

        if (!$package->getMaintainers()->contains($this->getUser()) && !$this->isGranted('ROLE_DELETE_PACKAGES')) {
            throw new AccessDeniedException;
        }

        if (!$this->isCsrfTokenValid('delete_version', $req->request->get('_token'))) {
            throw new AccessDeniedException;
        }

        $repo->remove($version);
        $this->getDoctrine()->getManager()->flush();
        $this->getDoctrine()->getManager()->clear();

        return new Response('', 204);
    }

    /**
     * @Route("/packages/{name}", name="update_package", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, defaults={"_format" = "json"})
     * @Method({"PUT"})
     */
    public function updatePackageAction(Request $req, $name)
    {
        $doctrine = $this->getDoctrine();

        try {
            /** @var Package $package */
            $package = $doctrine
                ->getRepository('PackagistWebBundle:Package')
                ->getPackageByName($name);
        } catch (NoResultException $e) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Package not found',)), 404);
        }

        $username = $req->request->has('username') ?
            $req->request->get('username') :
            $req->query->get('username');

        $apiToken = $req->request->has('apiToken') ?
            $req->request->get('apiToken') :
            $req->query->get('apiToken');

        $update = $req->request->get('update', $req->query->get('update'));
        $autoUpdated = $req->request->get('autoUpdated', $req->query->get('autoUpdated'));
        $updateEqualRefs = $req->request->get('updateAll', $req->query->get('updateAll'));
        $showOutput = $req->request->get('showOutput', $req->query->get('showOutput', false));

        $user = $this->getUser() ?: $doctrine
            ->getRepository('PackagistWebBundle:User')
            ->findOneBy(array('username' => $username, 'apiToken' => $apiToken));

        if (!$user) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Invalid credentials',)), 403);
        }

        if ($package->getMaintainers()->contains($user) || $this->isGranted('ROLE_UPDATE_PACKAGES')) {
            $req->getSession()->save();

            if (null !== $autoUpdated) {
                $package->setAutoUpdated((Boolean) $autoUpdated);
                $doctrine->getManager()->flush();
            }

            if ($update) {
                set_time_limit(3600);
                $updater = $this->get('packagist.package_updater');

                $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE, new HtmlOutputFormatter(Factory::createAdditionalStyles()));
                $config = Factory::createConfig();
                $io->loadConfiguration($config);
                $repository = new VcsRepository(array('url' => $package->getRepository()), $io, $config);
                $loader = new ValidatingArrayLoader(new ArrayLoader());
                $repository->setLoader($loader);

                try {
                    $updater->update($io, $config, $package, $repository, $updateEqualRefs ? Updater::UPDATE_EQUAL_REFS : 0);
                } catch (\Exception $e) {
                    return new JsonResponse([
                        'status' => 'error',
                        'message' => '['.get_class($e).'] '.$e->getMessage(),
                        'details' => '<pre>'.$io->getOutput().'</pre>',
                    ], 400);
                }

                if ($showOutput) {
                    return new JsonResponse([
                        'status' => 'error',
                        'message' => 'Update successful',
                        'details' => '<pre>'.$io->getOutput().'</pre>',
                    ], 400);
                }
            }

            return new Response('{"status": "success"}', 202);
        }

        return new JsonResponse(array('status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)',), 404);
    }

    /**
     * @Route("/packages/{name}", name="delete_package", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"})
     * @Method({"DELETE"})
     */
    public function deletePackageAction(Request $req, $name)
    {
        $doctrine = $this->getDoctrine();

        try {
            /** @var Package $package */
            $package = $doctrine
                ->getRepository('PackagistWebBundle:Package')
                ->findOneByName($name);
        } catch (NoResultException $e) {
            throw new NotFoundHttpException('The requested package, '.$name.', was not found.');
        }

        if (!$form = $this->createDeletePackageForm($package)) {
            throw new AccessDeniedException;
        }
        $form->submit($req->request->get('form'));
        if ($form->isValid()) {
            $req->getSession()->save();

            /** @var VersionRepository $versionRepo */
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
                /** @var \Solarium_Client $solarium */
                $solarium = $this->get('solarium.client');

                $update = $solarium->createUpdate();
                $update->addDeleteById($packageId);
                $update->addCommit();

                $solarium->update($update);
            } catch (\Solarium_Client_HttpException $e) {
            }

            return new Response('', 204);
        }

        return new Response('Invalid form input', 400);
    }

    /**
     * @Template("PackagistWebBundle:Package:viewPackage.html.twig")
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
            'versions' => null,
            'expandedVersion' => null,
            'version' => null,
            'addMaintainerForm' => $form->createView(),
            'show_add_maintainer_form' => true,
        );

        $form->handleRequest($req);
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
                $this->get('logger')->critical($e->getMessage(), array('exception', $e));
                $this->get('session')->getFlashBag()->set('error', 'The maintainer could not be added.');
            }
        }

        return $data;
    }

    /**
     * @Template("PackagistWebBundle:Package:viewPackage.html.twig")
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
            'versions' => null,
            'expandedVersion' => null,
            'version' => null,
            'removeMaintainerForm' => $removeMaintainerForm->createView(),
            'show_remove_maintainer_form' => true,
        );

        $removeMaintainerForm->handleRequest($req);
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
                $this->get('logger')->critical($e->getMessage(), array('exception', $e));
                $this->get('session')->getFlashBag()->set('error', 'The maintainer could not be removed.');
            }
        }

        return $data;
    }

    /**
     * @Template()
     * @Route(
     *     "/packages/{name}/edit",
     *     name="edit_package",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function editAction(Request $req, Package $package)
    {
        if (!$package->getMaintainers()->contains($this->getUser()) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $form = $this->createFormBuilder($package, array("validation_groups" => array("Update")))
            ->add('repository', TextType::class)
            ->setMethod('POST')
            ->setAction($this->generateUrl('edit_package', ['name' => $package->getName()]))
            ->getForm();

        $form->handleRequest($req);
        if ($form->isValid()) {
            // Force updating of packages once the package is viewed after the redirect.
            $package->setCrawledAt(null);

            $em = $this->getDoctrine()->getManager();
            $em->persist($package);
            $em->flush();

            $this->get("session")->getFlashBag()->set("success", "Changes saved.");

            return $this->redirect(
                $this->generateUrl("view_package", array("name" => $package->getName()))
            );
        }

        return array(
            "package" => $package, "form" => $form->createView()
        );
    }

    /**
     * @Route(
     *      "/packages/{name}/abandon",
     *      name="abandon_package",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     * @Template()
     */
    public function abandonAction(Request $request, Package $package)
    {
        if (!$package->getMaintainers()->contains($this->getUser()) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $form = $this->createForm(AbandonedType::class);
        $form->handleRequest($request);
        if ($form->isValid()) {
            $package->setAbandoned(true);
            $package->setReplacementPackage(str_replace('https://packagist.org/packages/', '', $form->get('replacement')->getData()));
            $package->setIndexedAt(null);

            $em = $this->getDoctrine()->getManager();
            $em->flush();

            return $this->redirect($this->generateUrl('view_package', array('name' => $package->getName())));
        }

        return array(
            'package' => $package,
            'form'    => $form->createView()
        );
    }

    /**
     * @Route(
     *      "/packages/{name}/unabandon",
     *      name="unabandon_package",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function unabandonAction(Package $package)
    {
        if (!$package->getMaintainers()->contains($this->getUser()) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $package->setAbandoned(false);
        $package->setReplacementPackage(null);
        $package->setIndexedAt(null);

        $em = $this->getDoctrine()->getManager();
        $em->flush();

        return $this->redirect($this->generateUrl('view_package', array('name' => $package->getName())));
    }

    /**
     * @Route(
     *      "/packages/{name}/stats.{_format}",
     *      name="view_package_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(json)"},
     *      defaults={"_format"="html"}
     * )
     * @Template()
     */
    public function statsAction(Request $req, Package $package)
    {
        $versions = $package->getVersions()->toArray();
        usort($versions, Package::class.'::sortVersions');
        $date = $this->guessStatsStartDate($package);
        $data = [
            'downloads' => $this->get('packagist.download_manager')->getDownloads($package),
            'versions' => $versions,
            'average' => $this->guessStatsAverage($date),
            'date' => $date->format('Y-m-d'),
        ];

        if ($req->getRequestFormat() === 'json') {
            $data['versions'] = array_map(function ($version) {
                /** @var Version $version */
                return $version->getVersion();
            }, $data['versions']);

            return new JsonResponse($data);
        }

        $data['package'] = $package;

        $expandedVersion = reset($versions);
        foreach ($versions as $v) {
            /** @var Version $v */
            if (!$v->isDevelopment()) {
                $expandedVersion = $v;
                break;
            }
        }
        $data['expandedId'] = $expandedVersion ? $expandedVersion->getId() : false;

        return $data;
    }

    /**
     * @Route(
     *      "/packages/{name}/dependents",
     *      name="view_package_dependents",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     * @Template()
     */
    public function dependentsAction(Request $req, $name)
    {
        $page = $req->query->get('page', 1);

        /** @var PackageRepository $repo */
        $repo = $this->getDoctrine()->getRepository('PackagistWebBundle:Package');
        $depCount = $repo->getDependentCount($name);
        $packages = $repo->getDependents($name, ($page - 1) * 15, 15);

        $paginator = new Pagerfanta(new FixedAdapter($depCount, $packages));
        $paginator->setMaxPerPage(15);
        $paginator->setCurrentPage($page, false, true);

        $data['packages'] = $paginator;
        $data['count'] = $depCount;

        $data['meta'] = $this->getPackagesMetadata($data['packages']);
        $data['name'] = $name;

        return $data;
    }

    /**
     * @Route(
     *      "/packages/{name}/stats/all.json",
     *      name="package_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function overallStatsAction(Request $req, Package $package, Version $version = null)
    {
        if ($from = $req->query->get('from')) {
            $from = new DateTimeImmutable($from);
        } else {
            $from = $this->guessStatsStartDate($version ?: $package);
        }
        if ($to = $req->query->get('to')) {
            $to = new DateTimeImmutable($to);
        } else {
            $to = new DateTimeImmutable('-2days 00:00:00');
        }
        $average = $req->query->get('average', $this->guessStatsAverage($from, $to));

        $datePoints = $this->createDatePoints($from, $to, $average, $package, $version);

        $redis = $this->get('snc_redis.default');
        if ($average === 'Daily') {
            $datePoints = array_map(function ($vals) {
                return $vals[0];
            }, $datePoints);

            $datePoints = array(
                'labels' => array_keys($datePoints),
                'values' => $redis->mget(array_values($datePoints))
            );
        } else {
            $datePoints = array(
                'labels' => array_keys($datePoints),
                'values' => array_values(array_map(function ($vals) use ($redis) {
                    return array_sum($redis->mget(array_values($vals)));
                }, $datePoints))
            );
        }

        $datePoints['average'] = $average;

        if ($average !== 'daily') {
            $dividers = [
                'monthly' => 30.41,
                'weekly' => 7,
            ];
            $divider = $dividers[$average];
            $datePoints['values'] = array_map(function ($val) use ($divider) {
                return ceil($val / $divider);
            }, $datePoints['values']);
        }

        if (empty($datePoints['labels']) && empty($datePoints['values'])) {
            $datePoints['labels'][] = date('Y-m-d');
            $datePoints['values'][] = 0;
        }

        $response = new JsonResponse($datePoints);
        $response->setSharedMaxAge(1800);

        return $response;
    }

    /**
     * @Route(
     *      "/packages/{name}/stats/{version}.json",
     *      name="version_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "version"=".+?"}
     * )
     */
    public function versionStatsAction(Request $req, Package $package, $version)
    {
        $normalizer = new VersionParser;
        $normVersion = $normalizer->normalize($version);

        $version = $this->getDoctrine()->getRepository('PackagistWebBundle:Version')->findOneBy([
            'package' => $package,
            'normalizedVersion' => $normVersion
        ]);

        if (!$version) {
            throw new NotFoundHttpException();
        }

        return $this->overallStatsAction($req, $package, $version);
    }

    private function createAddMaintainerForm(Package $package)
    {
        if (!$user = $this->getUser()) {
            return;
        }

        if ($this->isGranted('ROLE_EDIT_PACKAGES') || $package->getMaintainers()->contains($user)) {
            $maintainerRequest = new MaintainerRequest();
            return $this->createForm(AddMaintainerRequestType::class, $maintainerRequest);
        }
    }

    private function createRemoveMaintainerForm(Package $package)
    {
        if (!($user = $this->getUser()) || 1 == $package->getMaintainers()->count()) {
            return;
        }

        if ($this->isGranted('ROLE_EDIT_PACKAGES') || $package->getMaintainers()->contains($user)) {
            $maintainerRequest = new MaintainerRequest();
            return $this->createForm(RemoveMaintainerRequestType::class, $maintainerRequest, array(
                'package' => $package,
            ));
        }
    }

    private function createDeletePackageForm(Package $package)
    {
        if (!$user = $this->getUser()) {
            return;
        }

        // super admins bypass additional checks
        if (!$this->isGranted('ROLE_DELETE_PACKAGES')) {
            // non maintainers can not delete
            if (!$package->getMaintainers()->contains($user)) {
                return;
            }

            try {
                $downloads = $this->get('packagist.download_manager')->getTotalDownloads($package);
            } catch (ConnectionException $e) {
                return;
            }

            // more than 100 downloads = established package, do not allow deletion by maintainers
            if ($downloads > 100) {
                return;
            }
        }

        return $this->createFormBuilder(array())->getForm();
    }

    private function createDatePoints(DateTimeImmutable $from, DateTimeImmutable $to, $average, Package $package, Version $version = null)
    {
        $interval = $this->getStatsInterval($average);

        $dateKey = $average === 'monthly' ? 'Ym' : 'Ymd';
        $dateFormat = $average === 'monthly' ? 'Y-m' : 'Y-m-d';
        $dateJump = $average === 'monthly' ? '+1month' : '+1day';
        if ($average === 'monthly') {
            $to = new DateTimeImmutable('last day of '.$to->format('Y-m'));
        }

        $nextDataPointLabel = $from->format($dateFormat);
        $nextDataPoint = $from->modify($interval);

        $datePoints = [];
        while ($from <= $to) {
            $datePoints[$nextDataPointLabel][] = 'dl:'.$package->getId().($version ? '-' . $version->getId() : '').':'.$from->format($dateKey);

            $from = $from->modify($dateJump);
            if ($from >= $nextDataPoint) {
                $nextDataPointLabel = $from->format($dateFormat);
                $nextDataPoint = $from->modify($interval);
            }
        }

        return $datePoints;
    }

    private function guessStatsStartDate($packageOrVersion)
    {
        if ($packageOrVersion instanceof Package) {
            $date = DateTimeImmutable::createFromMutable($packageOrVersion->getCreatedAt());
        } elseif ($packageOrVersion instanceof Version) {
            $date = DateTimeImmutable::createFromMutable($packageOrVersion->getReleasedAt());
        } else {
            throw new \LogicException('Version or Package expected');
        }

        $statsRecordDate = new DateTimeImmutable('2012-04-13 00:00:00');
        if ($date < $statsRecordDate) {
            $date = $statsRecordDate;
        }

        return $date->setTime(0, 0, 0);
    }

    private function guessStatsAverage(DateTimeImmutable $from, DateTimeImmutable $to = null)
    {
        if ($to === null) {
            $to = new DateTimeImmutable('-2 days');
        }
        if ($from < $to->modify('-48months')) {
            $average = 'monthly';
        } elseif ($from < $to->modify('-7months')) {
            $average = 'weekly';
        } else {
            $average = 'daily';
        }

        return $average;
    }

    private function getStatsInterval($average)
    {
        $intervals = [
            'monthly' => '+1month',
            'weekly' => '+7days',
            'daily' => '+1day',
        ];

        if (!isset($intervals[$average])) {
            throw new BadRequestHttpException();
        }

        return $intervals[$average];
    }
}
