<?php

namespace App\Controller;

use App\Attribute\VarName;
use App\Entity\Dependent;
use App\Entity\PhpStat;
use App\Util\Killswitch;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use Composer\Package\Version\VersionParser;
use Composer\Pcre\Preg;
use Composer\Semver\Constraint\Constraint;
use DateTime;
use DateTimeImmutable;
use Doctrine\ORM\NoResultException;
use App\Entity\Download;
use App\Entity\Job;
use App\Entity\Package;
use App\Entity\SecurityAdvisory;
use App\Entity\SecurityAdvisoryRepository;
use App\Entity\Version;
use App\Entity\Vendor;
use App\Entity\User;
use App\Form\Model\MaintainerRequest;
use App\Form\Type\AbandonedType;
use App\Form\Type\AddMaintainerRequestType;
use App\Form\Type\PackageType;
use App\Form\Type\RemoveMaintainerRequestType;
use App\Model\PackageManager;
use App\Model\ProviderManager;
use Pagerfanta\Adapter\FixedAdapter;
use Pagerfanta\Pagerfanta;
use Predis\Connection\ConnectionException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use App\Service\GitHubUserMigrationWorker;
use App\Service\Scheduler;
use Symfony\Component\Routing\RouterInterface;
use Predis\Client as RedisClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;

class PackageController extends Controller
{
    public function __construct(
        private ProviderManager $providerManager,
        private PackageManager $packageManager,
        private Scheduler $scheduler,
        private FavoriteManager $favoriteManager,
        private DownloadManager $downloadManager,
    ) {}

    /**
     * @Route("/packages/", name="allPackages")
     */
    public function allAction(): RedirectResponse
    {
        return $this->redirectToRoute('browse', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * @Route("/packages/list.json", name="list", defaults={"_format"="json"}, methods={"GET"})
     */
    public function listAction(Request $req): JsonResponse
    {
        $repo = $this->getEM()->getRepository(Package::class);
        $queryParams = $req->query->all();
        $fields = (array) ($queryParams['fields'] ?? []); // support single or multiple fields
        /** @var string[] $fields */
        $fields = array_intersect($fields, ['repository', 'type', 'abandoned']);

        if (count($fields) > 0) {
            $filters = array_filter([
                'type' => $req->query->get('type'),
                'vendor' => $req->query->get('vendor'),
            ]);

            $response = new JsonResponse(['packages' => $repo->getPackagesWithFields($filters, $fields)]);
            $response->setSharedMaxAge(300);
            $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

            return $response;
        }

        if ($req->query->get('type')) {
            $names = $repo->getPackageNamesByType($req->query->get('type'));
        } elseif ($req->query->get('vendor')) {
            $names = $repo->getPackageNamesByVendor($req->query->get('vendor'));
        } else {
            $names = $this->providerManager->getPackageNames();
        }

        if ($req->query->get('filter')) {
            $packageFilter = '{^'.str_replace('\\*', '.*?', preg_quote($req->query->get('filter'))).'$}i';
            $filtered = [];
            foreach ($names as $name) {
                if (Preg::isMatch($packageFilter, $name)) {
                    $filtered[] = $name;
                }
            }
            $names = $filtered;
        }

        $response = new JsonResponse(['packageNames' => $names]);
        $response->setSharedMaxAge(300);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    /**
     * Deprecated legacy change API for metadata v1
     *
     * @Route("/packages/updated.json", name="updated_packages", defaults={"_format"="json"}, methods={"GET"})
     */
    public function updatedSinceAction(Request $req, RedisClient $redis): JsonResponse
    {
        $lastDumpTime = $redis->get('last_metadata_dump_time') ?: (time() - 60);

        $since = $req->query->get('since');
        if (!$since) {
            return new JsonResponse(['error' => 'Missing "since" query parameter with the latest timestamp you got from this endpoint', 'timestamp' => $lastDumpTime], 400);
        }

        try {
            $since = new DateTimeImmutable('@'.$since);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => 'Invalid "since" query parameter, make sure you store the timestamp returned and re-use it in the next query. Use '.$this->generateUrl('updated_packages', ['since' => time() - 180], UrlGeneratorInterface::ABSOLUTE_URL).' to initialize it.'], 400);
        }

        $repo = $this->getEM()->getRepository(Package::class);

        $names = $repo->getPackageNamesUpdatedSince($since);

        return new JsonResponse(['packageNames' => $names, 'timestamp' => $lastDumpTime]);
    }

    /**
     * @Route("/metadata/changes.json", name="metadata_changes", defaults={"_format"="json"}, methods={"GET"})
     */
    public function metadataChangesAction(Request $req, RedisClient $redis): JsonResponse
    {
        $topDump = $redis->zrevrange('metadata-dumps', 0, 0, ['WITHSCORES' => true]) ?: ['foo' => 0];
        $topDelete = $redis->zrevrange('metadata-deletes', 0, 0, ['WITHSCORES' => true]) ?: ['foo' => 0];
        $oldestSyncPoint = (int) $redis->get('metadata-oldest') ?: 15850612240000;
        $now = max((int) current($topDump), (int) current($topDelete)) + 1;

        $since = $req->query->getInt('since');
        if (!$since || $since < 15850612240000) {
            return new JsonResponse(['error' => 'Invalid or missing "since" query parameter, make sure you store the timestamp at the initial point you started mirroring, then send that to begin receiving changes, e.g. '.$this->generateUrl('metadata_changes', ['since' => $now], UrlGeneratorInterface::ABSOLUTE_URL).' for example.', 'timestamp' => $now], 400);
        }
        if ($since < $oldestSyncPoint) {
            return new JsonResponse(['actions' => [['type' => 'resync', 'time' => floor($now / 10000), 'package' => '*']], 'timestamp' => $now]);
        }

        // fetch changes from $since (inclusive) up to $now (non inclusive so -1)
        $dumps = $redis->zrangebyscore('metadata-dumps', $since, $now - 1, ['WITHSCORES' => true]);
        $deletes = $redis->zrangebyscore('metadata-deletes', $since, $now - 1, ['WITHSCORES' => true]);

        $actions = [];
        foreach ($dumps as $package => $time) {
            // we subtract .2s from the time for safety, as filemtime can be a little before the logged time (see https://gist.github.com/Seldaek/a5cf4a5551139bc41fb130fa16406290)
            $actions[$package] = ['type' => 'update', 'package' => $package, 'time' => floor(($time - 2000) / 10000)];
        }
        foreach ($deletes as $package => $time) {
            // if a package is dumped then deleted then dumped again because it gets re-added, we want to keep the update action
            // but if it is deleted and marked as dumped within 10 seconds of the deletion, it probably was a race condition between
            // dumped job and deletion, so let's replace it by a delete job anyway
            $newestUpdate = max($actions[$package]['time'] ?? 0, $actions[$package.'~dev']['time'] ?? 0);
            if ($newestUpdate < $time / 10000 + 10) {
                $actions[$package] = ['type' => 'delete', 'package' => $package, 'time' => floor(($time - 2000) / 10000)];
                unset($actions[$package.'~dev']);
            }
        }

        return new JsonResponse(['actions' => array_values($actions), 'timestamp' => $now]);
    }

    /**
     * @Route("/packages/submit", name="submit")
     */
    public function submitPackageAction(Request $req, GitHubUserMigrationWorker $githubUserMigrationWorker, RouterInterface $router, LoggerInterface $logger, MailerInterface $mailer, string $mailFromEmail, #[CurrentUser] User $user): Response
    {
        $package = new Package;
        $package->setEntityRepository($this->getEM()->getRepository(Package::class));
        $package->setRouter($router);
        $form = $this->createForm(PackageType::class, $package, [
            'action' => $this->generateUrl('submit'),
        ]);
        $package->addMaintainer($user);

        $form->handleRequest($req);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $em = $this->getEM();
                $em->persist($package);
                $em->flush();

                $this->providerManager->insertPackage($package);
                if ($user->getGithubToken()) {
                    try {
                        $githubUserMigrationWorker->setupWebHook($user->getGithubToken(), $package);
                    } catch (\Throwable $e) {
                        // ignore errors at this point
                    }
                }

                $this->addFlash('success', $package->getName().' has been added to the package list, the repository will now be crawled.');

                return $this->redirectToRoute('view_package', ['name' => $package->getName()]);
            } catch (\Exception $e) {
                $logger->critical($e->getMessage(), ['exception', $e]);
                $this->addFlash('error', $package->getName().' could not be saved.');
            }
        }

        return $this->render('package/submit_package.html.twig', ['form' => $form->createView(), 'page' => 'submit']);
    }

    /**
     * @Route("/packages/fetch-info", name="submit.fetch_info", defaults={"_format"="json"})
     */
    public function fetchInfoAction(Request $req, RouterInterface $router, #[CurrentUser] User $user): JsonResponse
    {
        $package = new Package;
        $package->setEntityRepository($this->getEM()->getRepository(Package::class));
        $package->setRouter($router);
        $form = $this->createForm(PackageType::class, $package);
        $package->addMaintainer($user);

        $form->handleRequest($req);
        if ($form->isSubmitted() && $form->isValid()) {
            $existingPackages = $this->getEM()
                ->getConnection()
                ->fetchAllAssociative(
                    'SELECT name FROM package WHERE name LIKE :query',
                    ['query' => '%/'.$package->getPackageName()]
                );

            $similar = [];

            foreach ($existingPackages as $existingPackage) {
                $similar[] = [
                    'name' => $existingPackage['name'],
                    'url' => $this->generateUrl('view_package', ['name' => $existingPackage['name']], UrlGeneratorInterface::ABSOLUTE_URL),
                ];
            }

            return new JsonResponse(['status' => 'success', 'name' => $package->getName(), 'similar' => $similar]);
        }

        if ($form->isSubmitted()) {
            $errors = [];
            if (count($form->getErrors())) {
                foreach ($form->getErrors() as $error) {
                    if ($error instanceof FormError) {
                        $errors[] = $error->getMessage();
                    }
                }
            }
            foreach ($form->all() as $child) {
                if (count($child->getErrors())) {
                    foreach ($child->getErrors() as $error) {
                        if ($error instanceof FormError) {
                            $errors[] = $error->getMessage();
                        }
                    }
                }
            }

            return new JsonResponse(['status' => 'error', 'reason' => $errors]);
        }

        return new JsonResponse(['status' => 'error', 'reason' => 'No data posted.']);
    }

    /**
     * @Route("/packages/{vendor}/", name="view_vendor", requirements={"vendor"="[A-Za-z0-9_.-]+"})
     */
    public function viewVendorAction(string $vendor): Response
    {
        $packages = $this->getEM()
            ->getRepository(Package::class)
            ->getFilteredQueryBuilder(['vendor' => $vendor], true)
            ->getQuery()
            ->getResult();

        if (!$packages) {
            return $this->redirectToRoute('search_web', ['q' => $vendor, 'reason' => 'vendor_not_found']);
        }

        return $this->render('package/view_vendor.html.twig', [
            'packages' => $packages,
            'meta' => $this->getPackagesMetadata($this->favoriteManager, $this->downloadManager, $packages),
            'vendor' => $vendor,
            'paginate' => false,
        ]);
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
    public function viewPackageAliasAction(Request $req, string $name): RedirectResponse
    {
        $format = $req->getRequestFormat();
        if ($format === 'html') {
            $format = null;
        }
        if ($format === 'json' || (!$format && str_ends_with($name, '.json'))) {
            throw new NotFoundHttpException('Package not found');
        }
        if (!str_contains(trim($name, '/'), '/')) {
            return $this->redirect($this->generateUrl('view_vendor', ['vendor' => $name, '_format' => $format]));
        }

        return $this->redirect($this->generateUrl('view_package', ['name' => trim($name, '/'), '_format' => $format]));
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
    public function viewProvidersAction(Request $req, string $name, RedisClient $redis): Response
    {
        $repo = $this->getEM()->getRepository(Package::class);
        $providers = $repo->findProviders($name);
        if (!$providers) {
            if ($req->getRequestFormat() === 'json') {
                return new JsonResponse(['providers' => []]);
            }

            return $this->redirect($this->generateUrl('search_web', ['q' => $name, 'reason' => 'package_not_found']));
        }

        if ($req->getRequestFormat() !== 'json') {
            $package = $repo->findOneBy(['name' => $name]);
            if ($package) {
                $providers[] = $package;
            }
        }

        try {
            $trendiness = [];
            foreach ($providers as $package) {
                /** @var Package $package */
                $trendiness[$package->getId()] = (int) $redis->zscore('downloads:trending', $package->getId());
            }
            usort($providers, function (Package $a, Package $b) use ($trendiness) {
                if ($trendiness[$a->getId()] === $trendiness[$b->getId()]) {
                    return strcmp($a->getName(), $b->getName());
                }
                return $trendiness[$a->getId()] > $trendiness[$b->getId()] ? -1 : 1;
            });
        } catch (ConnectionException $e) {}

        if ($req->getRequestFormat() === 'json') {
            $response = [];
            foreach ($providers as $package) {
                $response[] = [
                    'name' => $package->getName(),
                    'description' => $package->getDescription(),
                    'type' => $package->getType(),
                ];
            }

            return new JsonResponse(['providers' => $response]);
        }

        return $this->render('package/providers.html.twig', [
            'name' => $name,
            'packages' => $providers,
            'meta' => $this->getPackagesMetadata($this->favoriteManager, $this->downloadManager, $providers),
            'paginate' => false,
        ]);
    }

    /**
     * @Route(
     *     "/spam",
     *     name="view_spam",
     *     defaults={"_format"="html"},
     *     methods={"GET"}
     * )
     */
    public function viewSpamAction(Request $req, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
        if (!$this->getUser() || !$this->isGranted('ROLE_ANTISPAM')) {
            throw new NotFoundHttpException();
        }

        $page = max(1, $req->query->getInt('page', 1));

        $repo = $this->getEM()->getRepository(Package::class);
        $count = $repo->getSuspectPackageCount();
        $packages = $repo->getSuspectPackages(($page - 1) * 50, 50);

        $paginator = new Pagerfanta(new FixedAdapter($count, $packages));
        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage(50);
        $paginator->setCurrentPage($page);

        $data['packages'] = $paginator;
        $data['count'] = $count;
        $data['meta'] = $this->getPackagesMetadata($this->favoriteManager, $this->downloadManager, $data['packages']);
        $data['markSafeCsrfToken'] = $csrfTokenManager->getToken('mark_safe');

        $vendorRepo = $this->getEM()->getRepository(Vendor::class);
        $verified = [];
        foreach ($packages as $pkg) {
            $dls = $data['meta']['downloads'][$pkg['id']] ?? 0;
            $vendor = Preg::replace('{/.*$}', '', $pkg['name']);
            if ($dls > 10 && !in_array($vendor, $verified, true)) {
                $vendorRepo->verify($vendor);
                $this->addFlash('success', 'Marked '.$vendor.' with '.$dls.' downloads.');
                $verified[] = $vendor;
            }
        }

        if ($verified) {
            return $this->redirectToRoute('view_spam');
        }

        return $this->render('package/spam.html.twig', $data);
    }

    /**
     * @Route(
     *     "/spam/nospam",
     *     name="mark_nospam",
     *     defaults={"_format"="html"},
     *     methods={"POST"}
     * )
     * @IsGranted("ROLE_ANTISPAM")
     */
    public function markSafeAction(Request $req): RedirectResponse
    {
        /** @var string[] $vendors */
        $vendors = array_filter($req->request->all('vendor'));
        if (!$this->isCsrfTokenValid('mark_safe', (string)$req->request->get('token'))) {
            throw new BadRequestHttpException('Invalid CSRF token');
        }

        $repo = $this->getEM()->getRepository(Vendor::class);
        foreach ($vendors as $vendor) {
            $repo->verify($vendor);
        }

        return $this->redirectToRoute('view_spam');
    }

    /**
     * @Route(
     *     "/packages/{name}.{_format}",
     *     name="view_package",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(json)"},
     *     defaults={"_format"="html"},
     *     methods={"GET"}
     * )
     */
    public function viewPackageAction(Request $req, string $name, CsrfTokenManagerInterface $csrfTokenManager, #[CurrentUser] User $user = null): Response
    {
        if (!Killswitch::isEnabled(Killswitch::PAGES_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }

        if ($req->getSession()->isStarted()) {
            $req->getSession()->save();
        }

        if (Preg::isMatch('{^(?P<pkg>ext-[a-z0-9_.-]+?)/(?P<method>dependents|suggesters)$}i', $name, $match)) {
            return $this->{$match['method'].'Action'}($req, $match['pkg']);
        }

        $repo = $this->getEM()->getRepository(Package::class);

        try {
            $package = $repo->getPartialPackageByNameWithVersions($name);
        } catch (NoResultException $e) {
            if ('json' === $req->getRequestFormat()) {
                return new JsonResponse(['status' => 'error', 'message' => 'Package not found'], 404);
            }

            if ($repo->findProviders($name)) {
                return $this->redirect($this->generateUrl('view_providers', ['name' => $name]));
            }

            return $this->redirect($this->generateUrl('search_web', ['q' => $name, 'reason' => 'package_not_found']));
        }

        if ($package->isAbandoned() && $package->getReplacementPackage() === 'spam/spam' && !$this->isGranted('ROLE_ADMIN')) {
            throw new NotFoundHttpException('This is a spam package');
        }

        if ('json' === $req->getRequestFormat()) {
            $data = $package->toArray($this->getEM()->getRepository(Version::class), true);
            if (Killswitch::isEnabled(Killswitch::LINKS_ENABLED)) {
                $data['dependents'] = $repo->getDependentCount($package->getName());
                $data['suggesters'] = $repo->getSuggestCount($package->getName());
            }

            try {
                if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
                    throw new \RuntimeException();
                }
                $data['downloads'] = $this->downloadManager->getDownloads($package);
                $data['favers'] = $this->favoriteManager->getFaverCount($package);
            } catch (\RuntimeException | ConnectionException $e) {
                $data['downloads'] = null;
                $data['favers'] = null;
            }

            if (empty($data['versions'])) {
                $data['versions'] = new \stdClass;
            }

            $response = new JsonResponse(['package' => $data]);
            if (Killswitch::isEnabled(Killswitch::LINKS_ENABLED) && Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
                $response->setSharedMaxAge(12 * 3600);
            }
            $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

            return $response;
        }

        $version = null;
        $expandedVersion = null;
        $versions = $package->getVersions()->toArray();

        usort($versions, Package::class.'::sortVersions');

        if (count($versions)) {
            $versionRepo = $this->getEM()->getRepository(Version::class);

            // load the default branch version as it is used to display the latest available source.* and homepage info
            $version = reset($versions);
            $this->getEM()->refresh($version);

            $expandedVersion = $version;
            foreach ($versions as $candidate) {
                if (!$candidate->isDevelopment()) {
                    $expandedVersion = $candidate;
                    break;
                }
            }

            // load the expanded version fully to be able to display all info including tags
            if ($expandedVersion->getId() !== $version->getId()) {
                $this->getEM()->refresh($expandedVersion);
            }
            $expandedVersion = $versionRepo->getFullVersion($expandedVersion->getId());
        }

        $data = [
            'package' => $package,
            'version' => $version,
            'versions' => $versions,
            'expandedVersion' => $expandedVersion,
        ];

        try {
            if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
                throw new \RuntimeException();
            }
            $data['downloads'] = $this->downloadManager->getDownloads($package, null, true);

            if (
                !$package->isSuspect()
                && $data['downloads']['total'] <= 10 && ($data['downloads']['views'] ?? 0) >= 100
                && $package->getCreatedAt()->getTimestamp() >= strtotime('2019-05-01')
            ) {
                $vendorRepo = $this->getEM()->getRepository(Vendor::class);
                if (!$vendorRepo->isVerified($package->getVendor())) {
                    $package->setSuspect('Too many views');
                    $repo->markPackageSuspect($package);
                }
            }

            if ($user) {
                $data['is_favorite'] = $this->favoriteManager->isMarked($user, $package);
            }
        } catch (\RuntimeException | ConnectionException) {
        }

        $data['dependents'] = Killswitch::isEnabled(Killswitch::PAGE_DETAILS_ENABLED) && Killswitch::isEnabled(Killswitch::LINKS_ENABLED) ? $repo->getDependentCount($package->getName()) : 0;
        $data['suggesters'] = Killswitch::isEnabled(Killswitch::PAGE_DETAILS_ENABLED) && Killswitch::isEnabled(Killswitch::LINKS_ENABLED) ? $repo->getSuggestCount($package->getName()) : 0;

        if (Killswitch::isEnabled(Killswitch::PAGE_DETAILS_ENABLED)) {
            $securityAdvisoryRepository = $this->getEM()->getRepository(SecurityAdvisory::class);
            $securityAdvisories = $securityAdvisoryRepository->getPackageSecurityAdvisories($package->getName());
            $data['securityAdvisories'] = count($securityAdvisories);
            $data['hasVersionSecurityAdvisories'] = [];
            foreach ($securityAdvisories as $advisory) {
                $versionParser = new VersionParser();
                $affectedVersionConstraint = $versionParser->parseConstraints($advisory['affectedVersions']);
                foreach ($versions as $version) {
                    if (!isset($data['hasVersionSecurityAdvisories'][$version->getId()]) && $affectedVersionConstraint->matches(new Constraint('=',
                            $version->getNormalizedVersion()))) {
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
        } else {
            $data['hasVersionSecurityAdvisories'] = [];
        }

        if ($this->isGranted('ROLE_DELETE_PACKAGES') || $package->isMaintainer($user)) {
            $data['deleteVersionCsrfToken'] = $csrfTokenManager->getToken('delete_version');
            $lastJob = $this->getEM()->getRepository(Job::class)->findLatestExecutedJob($package->getId(), 'package:updates');
            $data['lastJobWarning'] = null;
            $data['lastJobStatus'] = $lastJob?->getStatus();
            $data['lastJobMsg'] = $lastJob ? $lastJob->getResult()['message'] ?? '' : null;
            $data['lastJobDetails'] = $lastJob ? $lastJob->getResult()['details'] ?? '' : null;
            if ($lastJob) {
                switch ($lastJob->getStatus()) {
                    case Job::STATUS_COMPLETED:
                        if (str_contains($data['lastJobDetails'], 'does not match version')) {
                            $data['lastJobWarning'] = 'Some tags were ignored because of a version mismatch in composer.json, <a href="https://blog.packagist.com/tagged-a-new-release-for-composer-and-it-wont-show-up-on-packagist/">read more</a>.';
                        }
                        break;
                    case Job::STATUS_FAILED:
                        $data['lastJobWarning'] = 'The last update failed, see the log for more infos.';
                        break;
                    case Job::STATUS_ERRORED:
                        $data['lastJobWarning'] = 'The last update failed in an unexpected way.';
                        break;
                }
            }
        }

        if ($this->isGranted('ROLE_ANTISPAM')) {
            $data['markSafeCsrfToken'] = $csrfTokenManager->getToken('mark_safe');
        }

        return $this->render('package/view_package.html.twig', $data);
    }

    /**
     * @Route(
     *     "/packages/{name}/downloads.{_format}",
     *     name="package_downloads_full",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(json)"},
     *     methods={"GET"}
     * )
     */
    public function viewPackageDownloadsAction(Request $req, string $name): Response
    {
        if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }

        $repo = $this->getEM()->getRepository(Package::class);

        try {
            $package = $repo->getPartialPackageByNameWithVersions($name);
        } catch (NoResultException) {
            if ('json' === $req->getRequestFormat()) {
                return new JsonResponse(['status' => 'error', 'message' => 'Package not found'], 404);
            }

            if ($repo->findProviders($name)) {
                return $this->redirect($this->generateUrl('view_providers', ['name' => $name]));
            }

            return $this->redirect($this->generateUrl('search_web', ['q' => $name, 'reason' => 'package_not_found']));
        }

        $versions = $package->getVersions();
        $data = [
            'name' => $package->getName(),
        ];

        try {
            $data['downloads']['total'] = $this->downloadManager->getDownloads($package);
            $data['favers'] = $this->favoriteManager->getFaverCount($package);
        } catch (ConnectionException) {
            $data['downloads']['total'] = null;
            $data['favers'] = null;
        }

        foreach ($versions as $version) {
            try {
                $data['downloads']['versions'][$version->getVersion()] = $this->downloadManager->getDownloads($package, $version);
            } catch (ConnectionException) {
                $data['downloads']['versions'][$version->getVersion()] = null;
            }
        }

        $response = new JsonResponse(['package' => $data], 200);
        $response->setSharedMaxAge(3600);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

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
    public function viewPackageVersionAction(Request $req, int $versionId): JsonResponse
    {
        if ($req->getSession()->isStarted()) {
            $req->getSession()->save();
        }

        $repo = $this->getEM()->getRepository(Version::class);

        $html = $this->renderView(
            'package/version_details.html.twig',
            ['version' => $repo->getFullVersion($versionId)]
        );

        return new JsonResponse(['content' => $html]);
    }

    /**
     * @Route(
     *     "/versions/{versionId}/delete",
     *     name="delete_version",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "versionId"="[0-9]+"},
     *     methods={"DELETE"}
     * )
     */
    public function deletePackageVersionAction(Request $req, int $versionId, #[CurrentUser] User $user = null): Response
    {
        $repo = $this->getEM()->getRepository(Version::class);

        try {
            $version = $repo->getFullVersion($versionId);
        } catch (NoResultException) {
            return new Response('Version '.$versionId.' not found', 404);
        }
        $package = $version->getPackage();

        if (!$package->isMaintainer($user) && !$this->isGranted('ROLE_DELETE_PACKAGES')) {
            throw new AccessDeniedException;
        }

        if (!$this->isCsrfTokenValid('delete_version', (string)$req->request->get('_token'))) {
            throw new AccessDeniedException;
        }

        $repo->remove($version);
        $this->getEM()->flush();
        $this->getEM()->clear();

        return new Response('', 204);
    }

    /**
     * @Route("/packages/{name}", name="update_package", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, defaults={"_format" = "json"}, methods={"PUT"})
     */
    public function updatePackageAction(Request $req, string $name): Response
    {
        try {
            $package = $this->getEM()
                ->getRepository(Package::class)
                ->getPackageByName($name);
        } catch (NoResultException) {
            return new JsonResponse(['status' => 'error', 'message' => 'Package not found'], 404);
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

        $update = $req->request->getBoolean('update', $req->query->getBoolean('update'));
        $autoUpdated = $req->request->get('autoUpdated', $req->query->get('autoUpdated'));
        $updateEqualRefs = $req->request->getBoolean('updateAll', $req->query->getBoolean('updateAll'));
        $manualUpdate = $req->request->getBoolean('manualUpdate', $req->query->getBoolean('manualUpdate'));

        $user = $this->getUser() ?: $this->getEM()->getRepository(User::class)
            ->findOneBy(['usernameCanonical' => $username, 'apiToken' => $apiToken]);

        if (!$user instanceof User) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid credentials'], 403);
        }

        $canUpdatePackage = $package->isMaintainer($user) || $this->isGranted('ROLE_UPDATE_PACKAGES');
        if ($canUpdatePackage || !$package->wasUpdatedInTheLast24Hours()) {
            // do not let non-maintainers execute update with those flags
            if (!$canUpdatePackage) {
                $autoUpdated = null;
                $updateEqualRefs = false;
                $manualUpdate = false;
            }

            if (null !== $autoUpdated) {
                $package->setAutoUpdated(filter_var($autoUpdated, FILTER_VALIDATE_BOOLEAN) ? Package::AUTO_MANUAL_HOOK : 0);
                $this->getEM()->flush();
            }

            if ($update) {
                $job = $this->scheduler->scheduleUpdate($package, $updateEqualRefs, false, null, $manualUpdate);

                return new JsonResponse(['status' => 'success', 'job' => $job->getId()], 202);
            }

            return new JsonResponse(['status' => 'success'], 202);
        }

        if ($package->wasUpdatedInTheLast24Hours()) {
            return new JsonResponse(['status' => 'error', 'message' => 'Package was already updated in the last 24 hours'], 404);
        }

        return new JsonResponse(['status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)'], 404);
    }

    /**
     * @Route("/packages/{name}", name="delete_package", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, methods={"DELETE"})
     */
    public function deletePackageAction(Request $req, string $name): Response
    {
        try {
            $package = $this->getEM()
                ->getRepository(Package::class)
                ->getPartialPackageByNameWithVersions($name);
        } catch (NoResultException) {
            throw new NotFoundHttpException('The requested package, '.$name.', was not found.');
        }

        if (!$form = $this->createDeletePackageForm($package)) {
            throw new AccessDeniedException;
        }
        $form->submit($req->request->all('form'));
        if ($form->isSubmitted() && $form->isValid()) {
            if ($req->getSession()->isStarted()) {
                $req->getSession()->save();
            }

            $this->packageManager->deletePackage($package);

            return new Response('', 204);
        }

        return new Response('Invalid form input', 400);
    }

    /**
     * @Route("/packages/{name}/maintainers/", name="add_maintainer", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"})
     */
    public function createMaintainerAction(Request $req, #[VarName('name')] Package $package, LoggerInterface $logger): RedirectResponse
    {
        if (!$form = $this->createAddMaintainerForm($package)) {
            throw new AccessDeniedException('You must be a package\'s maintainer to modify maintainers.');
        }

        $form->handleRequest($req);
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $em = $this->getEM();
                if ($username = $form->getData()->getUser()) {
                    $user = $em->getRepository(User::class)->findOneByUsernameOrEmail($username);
                }

                if (!empty($user)) {
                    if (!$package->isMaintainer($user)) {
                        $package->addMaintainer($user);
                        $this->packageManager->notifyNewMaintainer($user, $package);
                    }

                    $em->persist($package);
                    $em->flush();

                    $this->addFlash('success', $user->getUsername().' is now a '.$package->getName().' maintainer.');

                    return $this->redirectToRoute('view_package', ['name' => $package->getName()]);
                }
                $this->addFlash('error', 'The user could not be found.');
            } catch (\Exception $e) {
                $logger->critical($e->getMessage(), ['exception', $e]);
                $this->addFlash('error', 'The maintainer could not be added.');
            }
        }

        return $this->redirectToRoute('view_package', ['name' => $package->getName()]);
    }

    /**
     * @Route("/packages/{name}/maintainers/delete", name="remove_maintainer", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"})
     */
    public function removeMaintainerAction(Request $req, #[VarName('name')] Package $package, LoggerInterface $logger): Response
    {
        if (!$removeMaintainerForm = $this->createRemoveMaintainerForm($package)) {
            throw new AccessDeniedException('You must be a package\'s maintainer to modify maintainers.');
        }

        $removeMaintainerForm->handleRequest($req);
        if ($removeMaintainerForm->isSubmitted() && $removeMaintainerForm->isValid()) {
            try {
                $em = $this->getEM();
                if ($username = $removeMaintainerForm->getData()->getUser()) {
                    $user = $em->getRepository(User::class)->findOneByUsernameOrEmail($username);
                }

                if (!empty($user)) {
                    if ($package->isMaintainer($user)) {
                        $package->getMaintainers()->removeElement($user);
                    }

                    $em->persist($package);
                    $em->flush();

                    $this->addFlash('success', $user->getUsername().' is no longer a '.$package->getName().' maintainer.');

                    return $this->redirectToRoute('view_package', ['name' => $package->getName()]);
                }
                $this->addFlash('error', 'The user could not be found.');
            } catch (\Exception $e) {
                $logger->critical($e->getMessage(), ['exception', $e]);
                $this->addFlash('error', 'The maintainer could not be removed.');
            }
        }

        return $this->render('package/view_package.html.twig', [
            'package' => $package,
            'versions' => null,
            'expandedVersion' => null,
            'version' => null,
            'removeMaintainerForm' => $removeMaintainerForm->createView(),
            'show_remove_maintainer_form' => true,
        ]);
    }

    /**
     * @Route(
     *     "/packages/{name}/edit",
     *     name="edit_package",
     *     requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function editAction(Request $req, #[VarName('name')] Package $package, #[CurrentUser] User $user = null): Response
    {
        if (!$package->isMaintainer($user) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $form = $this->createFormBuilder($package, ["validation_groups" => ["Update"]])
            ->add('repository', TextType::class)
            ->setMethod('POST')
            ->setAction($this->generateUrl('edit_package', ['name' => $package->getName()]))
            ->getForm();

        $form->handleRequest($req);
        if ($form->isSubmitted() && $form->isValid()) {
            // Force updating of packages once the package is viewed after the redirect.
            $package->setCrawledAt(null);

            $em = $this->getEM();
            $em->persist($package);
            $em->flush();

            $this->addFlash("success", "Changes saved.");

            return $this->redirectToRoute('view_package', ['name' => $package->getName()]);
        }

        return $this->render('package/edit.html.twig', [
            'package' => $package,
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route(
     *      "/packages/{name}/abandon",
     *      name="abandon_package",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function abandonAction(Request $request, #[VarName('name')] Package $package, #[CurrentUser] User $user = null): Response
    {
        if (!$package->isMaintainer($user) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $form = $this->createForm(AbandonedType::class);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $package->setAbandoned(true);
            $package->setReplacementPackage(str_replace('https://packagist.org/packages/', '', $form->get('replacement')->getData()));
            $package->setIndexedAt(null);
            $package->setCrawledAt(new DateTime());
            $package->setUpdatedAt(new DateTime());
            $package->setDumpedAt(null);
            $package->setDumpedAtV2(null);

            $em = $this->getEM();
            $em->flush();

            return $this->redirect($this->generateUrl('view_package', ['name' => $package->getName()]));
        }

        return $this->render('package/abandon.html.twig', [
            'package' => $package,
            'form'    => $form->createView(),
        ]);
    }

    /**
     * @Route(
     *      "/packages/{name}/unabandon",
     *      name="unabandon_package",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function unabandonAction(#[VarName('name')] Package $package, #[CurrentUser] User $user = null): RedirectResponse
    {
        if (!$package->isMaintainer($user) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $package->setAbandoned(false);
        $package->setReplacementPackage(null);
        $package->setIndexedAt(null);
        $package->setCrawledAt(new DateTime());
        $package->setUpdatedAt(new DateTime());
        $package->setDumpedAt(null);
        $package->setDumpedAtV2(null);

        $em = $this->getEM();
        $em->flush();

        return $this->redirectToRoute('view_package', ['name' => $package->getName()]);
    }

    /**
     * @Route(
     *      "/packages/{name}/stats.{_format}",
     *      name="view_package_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(json)"},
     *      defaults={"_format"="html"}
     * )
     */
    public function statsAction(Request $req, #[VarName('name')] Package $package): Response
    {
        if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }

        /** @var Version[] $versions */
        $versions = $package->getVersions()->toArray();
        usort($versions, Package::class.'::sortVersions');
        $date = $this->guessStatsStartDate($package);
        $data = [
            'downloads' => $this->downloadManager->getDownloads($package),
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
        $data['majorVersions'] = $majorVersions ? array_merge(['all'], array_unique($majorVersions)) : [];
        $data['expandedId'] = $majorVersions ? 'major/all' : ($expandedVersion ? $expandedVersion->getId() : false);

        return $this->render('package/stats.html.twig', $data);
    }

    /**
     * @Route(
     *      "/packages/{name}/php-stats.{_format}",
     *      name="view_package_php_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "_format"="(json)"},
     *      defaults={"_format"="html"}
     * )
     */
    public function phpStatsAction(Request $req, #[VarName('name')] Package $package): Response
    {
        if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }

        $phpStatRepo = $this->getEM()->getRepository(PhpStat::class);
        $versions = $phpStatRepo->getStatVersions($package);
        $defaultVersion = $this->getEM()->getConnection()->fetchOne('SELECT normalizedVersion from package_version WHERE package_id = :id AND defaultBranch = 1', ['id' => $package->getId()]);

        usort($versions, function ($a, $b) use ($defaultVersion) {
            if ($defaultVersion === $a['version'] && $b['depth'] !== PhpStat::DEPTH_PACKAGE) {
                return -1;
            }
            if ($defaultVersion === $b['version'] && $a['depth'] !== PhpStat::DEPTH_PACKAGE) {
                return 1;
            }

            if ($a['depth'] !== $b['depth']) {
                return $a['depth'] <=> $b['depth'];
            }

            if ($a['depth'] === PhpStat::DEPTH_EXACT) {
                return $a['version'] <=> $b['version'];
            }

            return version_compare($b['version'], $a['version']);
        });

        $versionsFormatted = [];
        foreach ($versions as $index => $version) {
            if ($version['version'] === '') {
                $label = 'All';
            } elseif (str_ends_with($version['version'], '.9999999')) {
                $label = Preg::replace('{\.9999999$}', '.x-dev', $version['version']);
            } elseif (in_array($version['depth'], [PhpStat::DEPTH_MINOR, PhpStat::DEPTH_MAJOR], true)) {
                $label = $version['version'].'.*';
            } else {
                $label = $version['version'];
            }
            $versionsFormatted[] = [
                'label' => $label,
                'version' => $version['version'] === '' ? 'all' : $version['version'],
                'depth' => match ($version['depth']) {
                    PhpStat::DEPTH_PACKAGE => 'package',
                    PhpStat::DEPTH_MAJOR => 'major',
                    PhpStat::DEPTH_MINOR => 'minor',
                    PhpStat::DEPTH_EXACT => 'exact',
                },
            ];
        }
        unset($versions);

        $date = $this->guessPhpStatsStartDate($package);
        $data = [
            'versions' => $versionsFormatted,
            'average' => $this->guessStatsAverage($date),
            'date' => $date->format('Y-m-d'),
        ];

        if ($req->getRequestFormat() === 'json') {
            return new JsonResponse($data);
        }

        $data['package'] = $package;

        $data['expandedVersion'] = $versionsFormatted ? reset($versionsFormatted)['version'] : null;

        return $this->render('package/php_stats.html.twig', $data);
    }

    /**
     * @Route(
     *      "/packages/{name}/php-stats/{type}/{version}.json",
     *      name="version_php_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "type"="platform|effective", "version"=".+"}
     * )
     */
    public function versionPhpStatsAction(Request $req, string $name, string $type, string $version): JsonResponse
    {
        if (!Killswitch::isEnabled(Killswitch::DOWNLOADS_ENABLED)) {
            return new JsonResponse(['status' => 'error', 'message' => 'This page is temporarily disabled, please come back later.',], Response::HTTP_BAD_GATEWAY);
        }

        try {
            $package = $this->getEM()
                ->getRepository(Package::class)
                ->getPackageByName($name);
        } catch (NoResultException $e) {
            return new JsonResponse(['status' => 'error', 'message' => 'Package not found',], 404);
        }

        if ($from = $req->query->get('from')) {
            $from = new DateTimeImmutable($from);
        } else {
            $from = $this->guessPhpStatsStartDate($package);
        }
        if ($to = $req->query->get('to')) {
            $to = new DateTimeImmutable($to);
        } else {
            $to = new DateTimeImmutable('today 00:00:00');
        }

        $average = $req->query->get('average', $this->guessStatsAverage($from, $to));

        $phpStat = $this->getEM()->getRepository(PhpStat::class)->findOneBy(['package' => $package, 'type' => $type === 'platform' ? PhpStat::TYPE_PLATFORM : PhpStat::TYPE_PHP, 'version' => $version === 'all' ? '' : $version]);
        if (!$phpStat) {
            throw new NotFoundHttpException('No stats found for the requested version');
        }

        $datePoints = $this->createDatePoints($from, $to, $average);
        $series = [];
        $totals = array_fill(0, count($datePoints), 0);

        $index = 0;
        foreach ($datePoints as $label => $values) {
            foreach ($phpStat->getData() as $seriesName => $seriesData) {
                $value = 0;
                foreach ($values as $valueKey) {
                    $value += $seriesData[$valueKey] ?? 0;
                }
                // average the value over the datapoints in this current label
                $value = (int) ceil($value / count($values));

                $series[$seriesName][] = $value;
                $totals[$index] += $value;
            }
            $index++;
        }

        // filter out series which have only 0 values
        foreach ($series as $seriesName => $data) {
            foreach ($data as $value) {
                if ($value !== 0) {
                    continue 2;
                }
            }
            unset($series[$seriesName]);
        }

        // delete last datapoint or two if they are still 0 as the nightly job syncing the data in mysql may not have run yet
        for ($i = 0; $i < 2; $i++) {
            if (0 === $totals[count($totals) - 1]) {
                unset($totals[count($totals) - 1]);
                end($datePoints);
                unset($datePoints[key($datePoints)]);
                foreach ($series as $seriesName => $data) {
                    unset($series[$seriesName][count($data) - 1]);
                }
            }
        }

        uksort($series, function ($a, $b) {
            if ($a === 'hhvm') {
                return 1;
            }
            if ($b === 'hhvm') {
                return -1;
            }
            return $b <=> $a;
        });

        $datePoints = [
            'labels' => array_keys($datePoints),
            'values' => $series,
        ];

        $datePoints['average'] = $average;

        if (empty($datePoints['labels']) && empty($datePoints['values'])) {
            $datePoints['labels'][] = date('Y-m-d');
            $datePoints['values'][] = [0];
        }

        $response = new JsonResponse($datePoints);
        $response->setSharedMaxAge(1800);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    /**
     * @Route(
     *      "/packages/{name}/dependents.{_format}",
     *      name="view_package_dependents",
     *      requirements={"name"="([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?|ext-[A-Za-z0-9_.-]+?)"},
     *      defaults={"_format"="html"}
     * )
     */
    public function dependentsAction(Request $req, string $name): Response
    {
        if (!Killswitch::isEnabled(Killswitch::LINKS_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }
        $page = max(1, $req->query->getInt('page', 1));
        $perPage = 15;
        $orderBy = $req->query->get('order_by', 'name');
        $requires = $req->query->get('requires', 'all');

        if ($req->getRequestFormat() === 'json') {
            $perPage = 100;
        }

        $repo = $this->getEM()->getRepository(Package::class);
        $requireType = null;
        if ($requires === 'require') {
            $requireType = Dependent::TYPE_REQUIRE;
        } elseif ($requires === 'require-dev') {
            $requireType = Dependent::TYPE_REQUIRE_DEV;
        }
        $depCount = $repo->getDependentCount($name, $requireType);
        $packages = $repo->getDependents($name, ($page - 1) * $perPage, $perPage, $orderBy, $requireType);

        $paginator = new Pagerfanta(new FixedAdapter($depCount, $packages));
        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage($perPage);
        $paginator->setCurrentPage($page);

        if ($req->getRequestFormat() === 'json') {
            $data = [
                'packages' => $paginator->getCurrentPageResults(),
            ];
            $meta = $this->getPackagesMetadata($this->favoriteManager, $this->downloadManager, $data['packages']);
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

        $data['meta'] = $this->getPackagesMetadata($this->favoriteManager, $this->downloadManager, $data['packages']);
        $data['name'] = $name;
        $data['order_by'] = $orderBy;
        $data['requires'] = $requires;

        return $this->render('package/dependents.html.twig', $data);
    }

    /**
     * @Route(
     *      "/packages/{name}/suggesters.{_format}",
     *      name="view_package_suggesters",
     *      requirements={"name"="([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?|ext-[A-Za-z0-9_.-]+?)"},
     *      defaults={"_format"="html"}
     * )
     */
    public function suggestersAction(Request $req, string $name): Response
    {
        if (!Killswitch::isEnabled(Killswitch::LINKS_ENABLED)) {
            return new Response('This page is temporarily disabled, please come back later.', Response::HTTP_BAD_GATEWAY);
        }
        $page = max(1, $req->query->getInt('page', 1));
        $perPage = 15;

        if ($req->getRequestFormat() === 'json') {
            $perPage = 100;
        }

        $repo = $this->getEM()->getRepository(Package::class);
        $suggestCount = $repo->getSuggestCount($name);
        $packages = $repo->getSuggests($name, ($page - 1) * $perPage, $perPage);

        $paginator = new Pagerfanta(new FixedAdapter($suggestCount, $packages));
        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage($perPage);
        $paginator->setCurrentPage($page);

        if ($req->getRequestFormat() === 'json') {
            $data = [
                'packages' => $paginator->getCurrentPageResults(),
            ];
            $meta = $this->getPackagesMetadata($this->favoriteManager, $this->downloadManager, $data['packages']);
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

        $data['meta'] = $this->getPackagesMetadata($this->favoriteManager, $this->downloadManager, $data['packages']);
        $data['name'] = $name;

        return $this->render('package/suggesters.html.twig', $data);
    }

    /**
     * @Route(
     *      "/packages/{name}/stats/all.json",
     *      name="package_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"}
     * )
     */
    public function overallStatsAction(Request $req, #[VarName('name')] Package $package, Version $version = null, string $majorVersion = null): JsonResponse
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
        if (null !== $majorVersion) {
            if ($majorVersion === 'all') {
                $dlData = $this->getEM()->getRepository(Download::class)->findDataByMajorVersions($package);
            } else {
                if (!is_numeric($majorVersion)) {
                    throw new BadRequestHttpException('Major version should be an int or "all"');
                }
                $dlData = $this->getEM()->getRepository(Download::class)->findDataByMajorVersion($package, (int) $majorVersion);
            }
        } elseif ($version) {
            $downloads = $this->getEM()->getRepository(Download::class)->findOneBy(['id' => $version->getId(), 'type' => Download::TYPE_VERSION]);
            $dlData[$version->getVersion()] = [$downloads ? $downloads->getData() : []];
        } else {
            $downloads = $this->getEM()->getRepository(Download::class)->findOneBy(['id' => $package->getId(), 'type' => Download::TYPE_PACKAGE]);
            $dlData[$package->getName()] = [$downloads ? $downloads->getData() : []];
        }

        $datePoints = $this->createDatePoints($from, $to, $average);
        $series = [];

        foreach ($datePoints as $values) {
            foreach ($dlData as $seriesName => $seriesData) {
                $value = 0;
                foreach ($values as $valueKey) {
                    foreach ($seriesData as $data) {
                        $value += $data[$valueKey] ?? 0;
                    }
                }
                $series[$seriesName][] = ceil($value / count($values));
            }
        }

        $datePoints = [
            'labels' => array_keys($datePoints),
            'values' => $series,
        ];

        $datePoints['average'] = $average;

        if (empty($datePoints['labels']) && empty($datePoints['values'])) {
            $datePoints['labels'][] = date('Y-m-d');
            $datePoints['values'][] = [0];
        }

        $response = new JsonResponse($datePoints);
        $response->setSharedMaxAge(1800);
        $response->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');

        return $response;
    }

    /**
     * @Route(
     *      "/packages/{name}/stats/major/{majorVersion}.json",
     *      name="major_version_stats",
     *      requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?", "majorVersion"="(all|[0-9]+?)"}
     * )
     */
    public function majorVersionStatsAction(Request $req, #[VarName('name')] Package $package, string $majorVersion): JsonResponse
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
    public function versionStatsAction(Request $req, #[VarName('name')] Package $package, string $version): JsonResponse
    {
        $normalizer = new VersionParser;
        $normVersion = $normalizer->normalize($version);

        $version = $this->getEM()->getRepository(Version::class)->findOneBy([
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
    public function securityAdvisoriesAction(Request $request, string $name): Response
    {
        /** @var SecurityAdvisoryRepository $repo */
        $repo = $this->getEM()->getRepository(SecurityAdvisory::class);
        $securityAdvisories = $repo->getPackageSecurityAdvisories($name);

        $data = [];
        $data['name'] = $name;

        $data['matchingAdvisories'] = [];
        if ($versionId = $request->query->getInt('version')) {
            $version = $this->getEM()->getRepository(Version::class)->findOneBy([
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

        return $this->render('package/security_advisories.html.twig', $data);
    }

    private function createAddMaintainerForm(Package $package): FormInterface|null
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }

        if (!$this->isGranted('ROLE_EDIT_PACKAGES') && !$package->isMaintainer($user)) {
            return null;
        }

        $maintainerRequest = new MaintainerRequest();
        return $this->createForm(AddMaintainerRequestType::class, $maintainerRequest);
    }

    private function createRemoveMaintainerForm(Package $package): FormInterface|null
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }

        if (1 === $package->getMaintainers()->count() || (!$this->isGranted('ROLE_EDIT_PACKAGES') && !$package->isMaintainer($user))) {
            return null;
        }

        $maintainerRequest = new MaintainerRequest();
        return $this->createForm(RemoveMaintainerRequestType::class, $maintainerRequest, [
            'package' => $package,
        ]);
    }

    private function createDeletePackageForm(Package $package): FormInterface|null
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            return null;
        }

        // super admins bypass additional checks
        if (!$this->isGranted('ROLE_DELETE_PACKAGES')) {
            // non maintainers can not delete
            if (!$package->isMaintainer($user)) {
                return null;
            }

            try {
                $downloads = $this->downloadManager->getTotalDownloads($package);
            } catch (ConnectionException $e) {
                return null;
            }

            // more than 500 downloads = established package, do not allow deletion by maintainers
            if ($downloads > 500) {
                return null;
            }
        }

        return $this->createFormBuilder([])->getForm();
    }

    /**
     * @return array<string, string[]>
     */
    private function createDatePoints(DateTimeImmutable $from, DateTimeImmutable $to, string $average): array
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

    private function guessStatsStartDate(Package|Version $packageOrVersion): DateTimeImmutable
    {
        if ($packageOrVersion instanceof Package) {
            $date = DateTimeImmutable::createFromInterface($packageOrVersion->getCreatedAt());
        } elseif ($packageOrVersion->getReleasedAt()) {
            $date = DateTimeImmutable::createFromInterface($packageOrVersion->getReleasedAt());
        } else {
            throw new \LogicException('Version with release date expected');
        }

        $statsRecordDate = new DateTimeImmutable('2012-04-13 00:00:00');
        if ($date < $statsRecordDate) {
            $date = $statsRecordDate;
        }

        return $date->setTime(0, 0, 0);
    }

    private function guessPhpStatsStartDate(Package $package): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromInterface($package->getCreatedAt());

        $statsRecordDate = new DateTimeImmutable('2021-05-18 00:00:00');
        if ($date < $statsRecordDate) {
            $date = $statsRecordDate;
        }

        return $date->setTime(0, 0, 0);
    }

    private function guessStatsAverage(DateTimeImmutable $from, DateTimeImmutable $to = null): string
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

    private function getStatsInterval(string $average): string
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
