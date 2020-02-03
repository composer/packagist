<?php

namespace Packagist\WebBundle\Controller;

use Composer\Package\Version\VersionParser;
use Composer\Semver\Constraint\Constraint;
use DateTimeImmutable;
use Doctrine\ORM\NoResultException;
use Packagist\WebBundle\Entity\Download;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\PackageRepository;
use Packagist\WebBundle\Entity\SecurityAdvisory;
use Packagist\WebBundle\Entity\SecurityAdvisoryRepository;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Entity\Vendor;
use Packagist\WebBundle\Entity\VersionRepository;
use Packagist\WebBundle\Form\Model\MaintainerRequest;
use Packagist\WebBundle\Form\Type\AbandonedType;
use Packagist\WebBundle\Form\Type\AddMaintainerRequestType;
use Packagist\WebBundle\Form\Type\PackageType;
use Packagist\WebBundle\Form\Type\RemoveMaintainerRequestType;
use Pagerfanta\Adapter\FixedAdapter;
use Pagerfanta\Pagerfanta;
use Predis\Connection\ConnectionException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Cache;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

class PackageController extends Controller
{
    /**
     * @Route("/packages/", name="allPackages")
     */
    public function allAction()
    {
        return new RedirectResponse($this->generateUrl('browse'), Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * @Route("/packages/list.json", name="list", defaults={"_format"="json"}, methods={"GET"})
     * @Cache(smaxage=300)
     */
    public function listAction(Request $req)
    {
        /** @var PackageRepository $repo */
        $repo = $this->getDoctrine()->getRepository(Package::class);
        $fields = (array) $req->query->get('fields', array());
        $fields = array_intersect($fields, array('repository', 'type'));

        if ($fields) {
            $filters = array_filter(array(
                'type' => $req->query->get('type'),
            ));

            return new JsonResponse(array('packages' => $repo->getPackagesWithFields($filters, $fields)));
        }

        if ($req->query->get('type')) {
            $names = $repo->getPackageNamesByType($req->query->get('type'));
        } elseif ($req->query->get('vendor')) {
            $names = $repo->getPackageNamesByVendor($req->query->get('vendor'));
        } else {
            $names = $this->get('packagist.provider_manager')->getPackageNames();
        }

        return new JsonResponse(array('packageNames' => $names));
    }

    /**
     * @Route("/packages/updated.json", name="updated_packages", defaults={"_format"="json"}, methods={"GET"})
     */
    public function updatedSinceAction(Request $req)
    {
        $lastDumpTime = $this->get('snc_redis.default_client')->get('last_metadata_dump_time') ?: (time() - 60);

        $since = $req->query->get('since');
        if (!$since) {
            return new JsonResponse(['error' => 'Missing "since" query parameter with the latest timestamp you got from this endpoint', 'timestamp' => $lastDumpTime], 400);
        }

        try {
            $since = new DateTimeImmutable('@'.$since);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Invalid "since" query parameter, make sure you store the timestamp returned and re-use it in the next query. Use '.$this->generateUrl('updated_packages', ['since' => time() - 180], UrlGeneratorInterface::ABSOLUTE_URL).' to initialize it.'], 400);
        }

        /** @var PackageRepository $repo */
        $repo = $this->getDoctrine()->getRepository(Package::class);

        $names = $repo->getPackageNamesUpdatedSince($since);

        return new JsonResponse(['packageNames' => $names, 'timestamp' => $lastDumpTime]);
    }

    /**
     * @Template()
     * @Route("/packages/submit", name="submit")
     */
    public function submitPackageAction(Request $req)
    {
        $user = $this->getUser();
        if (!$user->isEnabled()) {
            throw new AccessDeniedException();
        }

        $package = new Package;
        $package->setEntityRepository($this->getDoctrine()->getRepository(Package::class));
        $package->setRouter($this->get('router'));
        $form = $this->createForm(PackageType::class, $package, [
            'action' => $this->generateUrl('submit'),
        ]);
        $package->addMaintainer($user);

        $form->handleRequest($req);
        if ($form->isValid()) {
            try {
                $em = $this->getDoctrine()->getManager();
                $em->persist($package);
                $em->flush();

                $this->get('packagist.provider_manager')->insertPackage($package);
                if ($user->getGithubToken()) {
                    $this->get('github_user_migration_worker')->setupWebHook($user->getGithubToken(), $package);
                }

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
        $package->setEntityRepository($this->getDoctrine()->getRepository(Package::class));
        $package->setRouter($this->get('router'));
        $form = $this->createForm(PackageType::class, $package);
        $user = $this->getUser();
        $package->addMaintainer($user);

        $form->handleRequest($req);
        if ($form->isValid()) {
            list(, $name) = explode('/', $package->getName(), 2);

            $existingPackages = $this->getDoctrine()
                ->getConnection()
                ->fetchAll(
                    'SELECT name FROM package WHERE name LIKE :query',
                    ['query' => '%/'.$name]
                );

            $similar = array();

            foreach ($existingPackages as $existingPackage) {
                $similar[] = array(
                    'name' => $existingPackage['name'],
                    'url' => $this->generateUrl('view_package', array('name' => $existingPackage['name']), true),
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
            ->getRepository(Package::class)
            ->getFilteredQueryBuilder(['vendor' => $vendor.'/%'], true)
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
     *     defaults={"_format"="html"},
     *     methods={"GET"}
     * )
     * @Route(
     *     "/packages/{name}",
     *     name="view_package_alias2",
     *     requirements={"name"="[A-Za-z0-9_.-]+(/[A-Za-z0-9_.-]+?)?/"},
     *     defaults={"_format"="html"},
     *     methods={"GET"}
     * )
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
     *     "/providers/{name}.{_format}",
     *     name="view_providers",
     *     requirements={"name"="[A-Za-z0-9/_.-]+?", "_format"="(json)"},
     *     defaults={"_format"="html"},
     *     methods={"GET"}
     * )
     */
    public function viewProvidersAction(Request $req, $name)
    {
        /** @var PackageRepository $repo */
        $repo = $this->getDoctrine()->getRepository(Package::class);
        $providers = $repo->findProviders($name);
        if (!$providers) {
            if ($req->getRequestFormat() === 'json') {
                return new JsonResponse(['providers' => []]);
            }

            return $this->redirect($this->generateUrl('search', array('q' => $name, 'reason' => 'package_not_found')));
        }

        $package = $repo->findOneBy(['name' => $name]);
        if ($package) {
            $providers[] = $package;
        }

        try {
            $redis = $this->get('snc_redis.default_client');
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

        if ($req->getRequestFormat() === 'json') {
            foreach ($providers as $index => $package) {
                $providers[$index] = [
                    'name' => $package->getName(),
                    'description' => $package->getDescription(),
                    'type' => $package->getType(),
                    'repository' => $package->getRepository(),
                ];
            }

            return new JsonResponse(['providers' => $providers]);
        }

        return $this->render('PackagistWebBundle:package:providers.html.twig', array(
            'name' => $name,
            'packages' => $providers,
            'meta' => $this->getPackagesMetadata($providers),
            'paginate' => false,
        ));
    }

    /**
     * @Route(
     *     "/spam",
     *     name="view_spam",
     *     defaults={"_format"="html"},
     *     methods={"GET"}
     * )
     */
    public function viewSpamAction(Request $req)
    {
        if (!$this->getUser() || !$this->isGranted('ROLE_ANTISPAM')) {
            throw new NotFoundHttpException();
        }

        $page = max(1, (int) $req->query->get('page', 1));

        /** @var PackageRepository $repo */
        $repo = $this->getDoctrine()->getRepository(Package::class);
        $count = $repo->getSuspectPackageCount();
        $packages = $repo->getSuspectPackages(($page - 1) * 50, 50);

        $paginator = new Pagerfanta(new FixedAdapter($count, $packages));
        $paginator->setMaxPerPage(50);
        $paginator->setCurrentPage($page, false, true);

        $data['packages'] = $paginator;
        $data['count'] = $count;
        $data['meta'] = $this->getPackagesMetadata($data['packages']);
        $data['markSafeCsrfToken'] = $this->get('security.csrf.token_manager')->getToken('mark_safe');

        return $this->render('PackagistWebBundle:package:spam.html.twig', $data);
    }

    /**
     * @Route(
     *     "/spam/nospam",
     *     name="mark_nospam",
     *     defaults={"_format"="html"},
     *     methods={"POST"}
     * )
     */
    public function markSafeAction(Request $req)
    {
        if (!$this->getUser() || !$this->isGranted('ROLE_ANTISPAM')) {
            throw new NotFoundHttpException();
        }

        $expectedToken = $this->get('security.csrf.token_manager')->getToken('mark_safe')->getValue();

        $vendors = array_filter((array) $req->request->get('vendor'));
        if (!hash_equals($expectedToken, $req->request->get('token'))) {
            throw new BadRequestHttpException('Invalid CSRF token');
        }

        $repo = $this->getDoctrine()->getRepository(Vendor::class);
        foreach ($vendors as $vendor) {
            $repo->verify($vendor);
        }

        return $this->redirectToRoute('view_spam');
    }

    /**
     * @Template()
     * @Route(
     *     "/packages/{name}.{_format}",
     *     name="view_package",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(json)"},
     *     defaults={"_format"="html"},
     *     methods={"GET"}
     * )
     */
    public function viewPackageAction(Request $req, $name)
    {
        if ($req->getSession()->isStarted()) {
            $req->getSession()->save();
        }

        if (preg_match('{^(?P<pkg>ext-[a-z0-9_.-]+?)/(?P<method>dependents|suggesters)$}i', $name, $match)) {
            return $this->{$match['method'].'Action'}($req, $match['pkg']);
        }

        /** @var PackageRepository $repo */
        $repo = $this->getDoctrine()->getRepository(Package::class);

        try {
            /** @var Package $package */
            $package = $repo->getPartialPackageByNameWithVersions($name);
        } catch (NoResultException $e) {
            if ('json' === $req->getRequestFormat()) {
                return new JsonResponse(array('status' => 'error', 'message' => 'Package not found'), 404);
            }

            if ($providers = $repo->findProviders($name)) {
                return $this->redirect($this->generateUrl('view_providers', array('name' => $name)));
            }

            return $this->redirect($this->generateUrl('search', array('q' => $name, 'reason' => 'package_not_found')));
        }

        if ($package->isAbandoned() && $package->getReplacementPackage() === 'spam/spam') {
            throw new NotFoundHttpException('This is a spam package');
        }

        if ('json' === $req->getRequestFormat()) {
            $data = $package->toArray($this->getDoctrine()->getRepository(Version::class), true);
            $data['dependents'] = $repo->getDependantCount($package->getName());
            $data['suggesters'] = $repo->getSuggestCount($package->getName());

            try {
                $data['downloads'] = $this->get('packagist.download_manager')->getDownloads($package);
                $data['favers'] = $this->get('packagist.favorite_manager')->getFaverCount($package);
            } catch (ConnectionException $e) {
                $data['downloads'] = null;
                $data['favers'] = null;
            }

            if (empty($data['versions'])) {
                $data['versions'] = new \stdClass;
            }

            $response = new JsonResponse(array('package' => $data));
            $response->setSharedMaxAge(12*3600);

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
            $versionRepo = $this->getDoctrine()->getRepository(Version::class);
            $this->getDoctrine()->getManager()->refresh(reset($versions));
            $version = $versionRepo->getFullVersion(reset($versions)->getId());

            $expandedVersion = reset($versions);
            foreach ($versions as $v) {
                if (!$v->isDevelopment()) {
                    $expandedVersion = $v;
                    break;
                }
            }

            $this->getDoctrine()->getManager()->refresh($expandedVersion);
            $expandedVersion = $versionRepo->getFullVersion($expandedVersion->getId());
        }

        $data = array(
            'package' => $package,
            'version' => $version,
            'versions' => $versions,
            'expandedVersion' => $expandedVersion,
        );

        try {
            $data['downloads'] = $this->get('packagist.download_manager')->getDownloads($package, null, true);

            if (
                !$package->isSuspect()
                && ($data['downloads']['total'] ?? 0) <= 10 && ($data['downloads']['views'] ?? 0) >= 100
                && $package->getCreatedAt()->getTimestamp() >= strtotime('2019-05-01')
            ) {
                $vendorRepo = $this->getDoctrine()->getRepository(Vendor::class);
                if (!$vendorRepo->isVerified($package->getVendor())) {
                    $package->setSuspect('Too many views');
                    $repo->markPackageSuspect($package);
                }
            }

            if ($this->getUser()) {
                $data['is_favorite'] = $this->get('packagist.favorite_manager')->isMarked($this->getUser(), $package);
            }
        } catch (ConnectionException $e) {
        }

        $data['dependents'] = $repo->getDependantCount($package->getName());
        $data['suggesters'] = $repo->getSuggestCount($package->getName());

        /** @var SecurityAdvisoryRepository $securityAdvisoryRepository */
        $securityAdvisoryRepository = $this->getDoctrine()->getRepository(SecurityAdvisory::class);
        $securityAdvisories = $securityAdvisoryRepository->getPackageSecurityAdvisories($package->getName());
        $data['securityAdvisories'] = count($securityAdvisories);
        $data['hasVersionSecurityAdvisories'] = [];
        foreach ($securityAdvisories as $advisory) {
            $versionParser = new VersionParser();
            $affectedVersionConstraint = $versionParser->parseConstraints($advisory['affectedVersions']);
            foreach ($versions as $version) {
                if (!isset($data['hasVersionSecurityAdvisories'][$version->getId()]) && $affectedVersionConstraint->matches(new Constraint('=', $version->getNormalizedVersion()))) {
                    $data['hasVersionSecurityAdvisories'][$version->getId()] = true;
                }
            }
        }

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
        if ($this->isGranted('ROLE_ANTISPAM')) {
            $data['markSafeCsrfToken'] = $this->get('security.csrf.token_manager')->getToken('mark_safe');
        }

        return $data;
    }

    /**
     * @Route(
     *     "/packages/{name}/downloads.{_format}",
     *     name="package_downloads_full",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(json)"},
     *     methods={"GET"}
     * )
     */
    public function viewPackageDownloadsAction(Request $req, $name)
    {
        /** @var PackageRepository $repo */
        $repo = $this->getDoctrine()->getRepository(Package::class);

        try {
            /** @var $package Package */
            $package = $repo->getPartialPackageByNameWithVersions($name);
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
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "versionId"="[0-9]+", "_format"="(json)"},
     *     methods={"GET"}
     * )
     */
    public function viewPackageVersionAction(Request $req, $versionId)
    {
        if ($req->getSession()->isStarted()) {
            $req->getSession()->save();
        }

        /** @var VersionRepository $repo  */
        $repo = $this->getDoctrine()->getRepository(Version::class);

        $html = $this->renderView(
            'PackagistWebBundle:package:version_details.html.twig',
            array('version' => $repo->getFullVersion($versionId))
        );

        return new JsonResponse(array('content' => $html));
    }

    /**
     * @Route(
     *     "/versions/{versionId}/delete",
     *     name="delete_version",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "versionId"="[0-9]+"},
     *     methods={"DELETE"}
     * )
     */
    public function deletePackageVersionAction(Request $req, $versionId)
    {
        /** @var VersionRepository $repo  */
        $repo = $this->getDoctrine()->getRepository(Version::class);

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
     * @Route("/packages/{name}", name="update_package", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, defaults={"_format" = "json"}, methods={"PUT"})
     */
    public function updatePackageAction(Request $req, $name)
    {
        $doctrine = $this->getDoctrine();

        try {
            /** @var Package $package */
            $package = $doctrine
                ->getRepository(Package::class)
                ->getPackageByName($name);
        } catch (NoResultException $e) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Package not found',)), 404);
        }

        if ($package->isAbandoned() && $package->getReplacementPackage() === 'spam/spam') {
            throw new NotFoundHttpException('This is a spam package');
        }

        $username = $req->request->has('username') ?
            $req->request->get('username') :
            $req->query->get('username');

        $apiToken = $req->request->has('apiToken') ?
            $req->request->get('apiToken') :
            $req->query->get('apiToken');

        $update = $req->request->get('update', $req->query->get('update'));
        $autoUpdated = $req->request->get('autoUpdated', $req->query->get('autoUpdated'));
        $updateEqualRefs = (bool) $req->request->get('updateAll', $req->query->get('updateAll'));

        $user = $this->getUser() ?: $doctrine
            ->getRepository('PackagistWebBundle:User')
            ->findOneBy(array('username' => $username, 'apiToken' => $apiToken));

        if (!$user) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid credentials'], 403);
        }

        $canUpdatePackage = $package->getMaintainers()->contains($user) || $this->isGranted('ROLE_UPDATE_PACKAGES');
        if ($canUpdatePackage || !$package->wasUpdatedInTheLast24Hours()) {
            // do not let non-maintainers execute update with those flags
            if (!$canUpdatePackage) {
                $autoUpdated = null;
                $updateEqualRefs = false;
            }

            if (null !== $autoUpdated) {
                $package->setAutoUpdated($autoUpdated ? Package::AUTO_MANUAL_HOOK : 0);
                $doctrine->getManager()->flush();
            }

            if ($update) {
                $job = $this->get('scheduler')->scheduleUpdate($package, $updateEqualRefs);

                return new JsonResponse(['status' => 'success', 'job' => $job->getId()], 202);
            }

            return new JsonResponse(['status' => 'success'], 202);
        }

        if (!$canUpdatePackage && $package->wasUpdatedInTheLast24Hours()) {
            return new JsonResponse(['status' => 'error', 'message' => 'Package was already updated in the last 24 hours',], 404);
        }

        return new JsonResponse(array('status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)',), 404);
    }

    /**
     * @Route("/packages/{name}", name="delete_package", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, methods={"DELETE"})
     */
    public function deletePackageAction(Request $req, $name)
    {
        $doctrine = $this->getDoctrine();

        try {
            /** @var Package $package */
            $package = $doctrine
                ->getRepository(Package::class)
                ->getPartialPackageByNameWithVersions($name);
        } catch (NoResultException $e) {
            throw new NotFoundHttpException('The requested package, '.$name.', was not found.');
        }

        if (!$form = $this->createDeletePackageForm($package)) {
            throw new AccessDeniedException;
        }
        $form->submit($req->request->get('form'));
        if ($form->isValid()) {
            if ($req->getSession()->isStarted()) {
                $req->getSession()->save();
            }

            $this->get('packagist.package_manager')->deletePackage($package);

            return new Response('', 204);
        }

        return new Response('Invalid form input', 400);
    }

    /**
     * @Template("PackagistWebBundle:package:view_package.html.twig")
     * @Route("/packages/{name}/maintainers/", name="add_maintainer", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"})
     */
    public function createMaintainerAction(Request $req, $name)
    {
        /** @var $package Package */
        $package = $this->getDoctrine()
            ->getRepository(Package::class)
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
     * @Template("PackagistWebBundle:package:view_package.html.twig")
     * @Route("/packages/{name}/maintainers/delete", name="remove_maintainer", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"})
     */
    public function removeMaintainerAction(Request $req, $name)
    {
        /** @var $package Package */
        $package = $this->getDoctrine()
            ->getRepository(Package::class)
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
            $package->setCrawledAt(new \DateTime());
            $package->setUpdatedAt(new \DateTime());
            $package->setDumpedAt(null);

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
        $package->setCrawledAt(new \DateTime());
        $package->setUpdatedAt(new \DateTime());
        $package->setDumpedAt(null);

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
        /** @var Version[] $versions */
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
        $majorVersions = [];
        $foundExpandedVersion = false;
        foreach ($versions as $v) {
            if (!$v->isDevelopment()) {
                $majorVersions[] = $v->getMajorVersion();
                if (!$foundExpandedVersion) {
                    $expandedVersion = $v;
                    $foundExpandedVersion = true;
                }
            }
        }
        $data['majorVersions'] = array_unique($majorVersions);
        $data['expandedId'] = $expandedVersion ? $expandedVersion->getId() : false;

        return $data;
    }

    /**
     * @Route(
     *      "/packages/{name}/dependents.{_format}",
     *      name="view_package_dependents",
     *      requirements={"name"="([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?|ext-[A-Za-z0-9_.-]+?)"},
     *      defaults={"_format"="html"}
     * )
     */
    public function dependentsAction(Request $req, $name)
    {
        $page = max(1, (int) $req->query->get('page', 1));
        $perPage = 15;
        $orderBy = $req->query->get('order_by', 'name');

        if ($req->getRequestFormat() === 'json') {
            $perPage = 100;
        }

        /** @var PackageRepository $repo */
        $repo = $this->getDoctrine()->getRepository(Package::class);
        $depCount = $repo->getDependantCount($name);
        $packages = $repo->getDependents($name, ($page - 1) * $perPage, $perPage, $orderBy);

        $paginator = new Pagerfanta(new FixedAdapter($depCount, $packages));
        $paginator->setMaxPerPage($perPage);
        $paginator->setCurrentPage($page, false, true);

        if ($req->getRequestFormat() === 'json') {
            $data = [
                'packages' => $paginator->getCurrentPageResults(),
            ];
            $meta = $this->getPackagesMetadata($data['packages']);
            foreach ($data['packages'] as $index => $package) {
                $data['packages'][$index]['downloads'] = $meta['downloads'][$package['id']];
                $data['packages'][$index]['favers'] = $meta['favers'][$package['id']];
            }

            if ($paginator->hasNextPage()) {
                $data['next'] = $this->generateUrl('view_package_dependents', ['name' => $name, 'page' => $page + 1, '_format' => 'json', 'order_by' => $orderBy], UrlGeneratorInterface::ABSOLUTE_URL);
            }
            $data['ordered_by_name'] = $this->generateUrl('view_package_dependents', ['name' => $name, '_format' => 'json', 'order_by' => 'name'], UrlGeneratorInterface::ABSOLUTE_URL);
            $data['ordered_by_downloads'] = $this->generateUrl('view_package_dependents', ['name' => $name, '_format' => 'json', 'order_by' => 'downloads'], UrlGeneratorInterface::ABSOLUTE_URL);

            return new JsonResponse($data);
        }

        $data['packages'] = $paginator;
        $data['count'] = $depCount;

        $data['meta'] = $this->getPackagesMetadata($data['packages']);
        $data['name'] = $name;
        $data['order_by'] = $orderBy;

        return $this->render('PackagistWebBundle:package:dependents.html.twig', $data);
    }

    /**
     * @Route(
     *      "/packages/{name}/suggesters.{_format}",
     *      name="view_package_suggesters",
     *      requirements={"name"="([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?|ext-[A-Za-z0-9_.-]+?)"},
     *      defaults={"_format"="html"}
     * )
     */
    public function suggestersAction(Request $req, $name)
    {
        $page = max(1, (int) $req->query->get('page', 1));
        $perPage = 15;

        if ($req->getRequestFormat() === 'json') {
            $perPage = 100;
        }

        /** @var PackageRepository $repo */
        $repo = $this->getDoctrine()->getRepository(Package::class);
        $suggestCount = $repo->getSuggestCount($name);
        $packages = $repo->getSuggests($name, ($page - 1) * $perPage, $perPage);

        $paginator = new Pagerfanta(new FixedAdapter($suggestCount, $packages));
        $paginator->setMaxPerPage($perPage);
        $paginator->setCurrentPage($page, false, true);

        if ($req->getRequestFormat() === 'json') {
            $data = [
                'packages' => $paginator->getCurrentPageResults(),
            ];
            $meta = $this->getPackagesMetadata($data['packages']);
            foreach ($data['packages'] as $index => $package) {
                $data['packages'][$index]['downloads'] = $meta['downloads'][$package['id']];
                $data['packages'][$index]['favers'] = $meta['favers'][$package['id']];
            }

            if ($paginator->hasNextPage()) {
                $data['next'] = $this->generateUrl('view_package_suggesters', ['name' => $name, 'page' => $page + 1, '_format' => 'json'], UrlGeneratorInterface::ABSOLUTE_URL);
            }

            return new JsonResponse($data);
        }

        $data['packages'] = $paginator;
        $data['count'] = $suggestCount;

        $data['meta'] = $this->getPackagesMetadata($data['packages']);
        $data['name'] = $name;

        return $this->render('PackagistWebBundle:package:suggesters.html.twig', $data);
    }

    /**
     * @Route(
     *      "/packages/{name}/stats/all.json",
     *      name="package_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     * @ParamConverter("version", options={"exclude": {"name"}})
     */
    public function overallStatsAction(Request $req, Package $package, Version $version = null, int $majorVersion = null)
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

        $dlData = [];
        if ($majorVersion) {
            $dlData = $this->getDoctrine()->getRepository('PackagistWebBundle:Download')->findDataByMajorVersion($package, $majorVersion);
        } elseif ($version) {
            $downloads = $this->getDoctrine()->getRepository('PackagistWebBundle:Download')->findOneBy(['id' => $version->getId(), 'type' => Download::TYPE_VERSION]);
            $dlData[] = $downloads ? $downloads->getData() : [];
        } else {
            $downloads = $this->getDoctrine()->getRepository('PackagistWebBundle:Download')->findOneBy(['id' => $package->getId(), 'type' => Download::TYPE_PACKAGE]);
            $dlData[] = $downloads ? $downloads->getData() : [];
        }

        $datePoints = $this->createDatePoints($from, $to, $average);

        foreach ($datePoints as $label => $values) {
            $value = 0;
            foreach ($values as $valueKey) {
                foreach ($dlData as $data) {
                    $value += $data[$valueKey] ?? 0;
                }
            }
            $datePoints[$label] = ceil($value / count($values));
        }

        $datePoints = array(
            'labels' => array_keys($datePoints),
            'values' => array_values($datePoints),
        );

        $datePoints['average'] = $average;

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
     *      "/packages/{name}/stats/major/{majorVersion}.json",
     *      name="major_version_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "majorVersion"="[0-9]+?"}
     * )
     */
    public function majorVersionStatsAction(Request $req, Package $package, $majorVersion)
    {
        return $this->overallStatsAction($req, $package, null, $majorVersion);
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

        $version = $this->getDoctrine()->getRepository(Version::class)->findOneBy([
            'package' => $package,
            'normalizedVersion' => $normVersion
        ]);

        if (!$version) {
            throw new NotFoundHttpException();
        }

        return $this->overallStatsAction($req, $package, $version);
    }

    /**
     * @Route(
     *      "/packages/{name}/advisories",
     *      name="view_package_advisories",
     *      requirements={"name"="([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?|ext-[A-Za-z0-9_.-]+?)"}
     * )
     */
    public function securityAdvisoriesAction(Request $request, $name)
    {
        /** @var SecurityAdvisoryRepository $repo */
        $repo = $this->getDoctrine()->getRepository(SecurityAdvisory::class);
        $securityAdvisories = $repo->getPackageSecurityAdvisories($name);

        $data = [];
        $data['name'] = $name;

        $data['matchingAdvisories'] = [];
        if ($versionId = $request->query->getInt('version')) {
            $version = $this->getDoctrine()->getRepository(Version::class)->findOneBy([
                'name' => $name,
                'id' => $versionId,
            ]);
            if ($version) {
                $versionSecurityAdvisories = [];
                $versionParser = new VersionParser();
                foreach ($securityAdvisories as $advisory) {
                    $affectedVersionConstraint = $versionParser->parseConstraints($advisory['affectedVersions']);
                    if ($affectedVersionConstraint->matches(new Constraint('=', $version->getNormalizedVersion()))) {
                        $versionSecurityAdvisories[] = $advisory;
                    }
                }

                $data['version'] = $version->getVersion();
                $securityAdvisories = $versionSecurityAdvisories;
            }
        }

        $data['securityAdvisories'] = $securityAdvisories;
        $data['count'] = count($securityAdvisories);

        return $this->render('PackagistWebBundle:package:security_advisories.html.twig', $data);
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

    private function createDatePoints(DateTimeImmutable $from, DateTimeImmutable $to, $average)
    {
        $interval = $this->getStatsInterval($average);

        $dateKey = 'Ymd';
        $dateFormat = $average === 'monthly' ? 'Y-m' : 'Y-m-d';
        $dateJump = '+1day';

        $nextDataPointLabel = $from->format($dateFormat);

        if ($average === 'monthly') {
            $nextDataPoint = new DateTimeImmutable('first day of ' . $from->format('Y-m'));
            $nextDataPoint = $nextDataPoint->modify($interval);
        } else {
            $nextDataPoint = $from->modify($interval);
        }

        $datePoints = [];
        while ($from <= $to) {
            $datePoints[$nextDataPointLabel][] = $from->format($dateKey);

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
