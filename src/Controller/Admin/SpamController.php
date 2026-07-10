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

namespace App\Controller\Admin;

use App\Controller\Controller;
use App\Entity\Package;
use App\Entity\PackageRepository;
use App\Entity\Vendor;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use App\Service\Spam\SpamClassifier;
use Composer\Pcre\Preg;
use Pagerfanta\Adapter\FixedAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ANTISPAM')]
class SpamController extends Controller
{
    public function __construct(
        private FavoriteManager $favoriteManager,
        private DownloadManager $downloadManager,
        private SpamClassifier $spamClassifier,
    ) {
    }

    #[Route(path: '/admin/spam', name: 'view_spam', defaults: ['_format' => 'html'], methods: ['GET'])]
    public function viewSpamAction(Request $req, CsrfTokenManagerInterface $csrfTokenManager): Response
    {
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
        $data['meta']['spamScores'] = $this->computeSpamScores($repo, $packages);
        $data['markSafeCsrfToken'] = $csrfTokenManager->getToken('mark_safe');

        $vendorRepo = $this->getEM()->getRepository(Vendor::class);
        $verified = [];
        foreach ($packages as $pkg) {
            $dls = $data['meta']['downloads'][$pkg['id']] ?? 0;
            $vendor = Preg::replace('{/.*$}', '', $pkg['name']);
            if ($dls > 10 && !\in_array($vendor, $verified, true)) {
                $vendorRepo->verify($vendor);
                $this->addFlash('success', 'Marked '.$vendor.' with '.$dls.' downloads.');
                $verified[] = $vendor;
            }
        }

        if ($verified) {
            return $this->redirectToRoute('view_spam');
        }

        return $this->render('admin/spam.html.twig', $data);
    }

    /**
     * Runs the spam classifier over the listed packages so moderators can see, per package, how
     * spammy the model thinks it is and whether it would be auto-cleared. No-op (empty map) when no
     * model is deployed. The score is cheap enough to compute inline for a page of results.
     *
     * @param array<array{id: int, name: string, description: string|null}> $packages
     *
     * @return array<int, array{metadata: float, readme: float|null, safe: bool}>
     */
    private function computeSpamScores(PackageRepository $repo, array $packages): array
    {
        if (\count($packages) === 0 || !$this->spamClassifier->isModelAvailable()) {
            return [];
        }

        $ids = array_values(array_map(static fn (array $pkg) => $pkg['id'], $packages));
        $tagsById = $repo->getTagsByPackageIds($ids);
        $readmesById = $repo->getReadmeContentsByPackageIds($ids);

        $scores = [];
        foreach ($packages as $pkg) {
            $scores[$pkg['id']] = $this->spamClassifier->evaluate(
                $pkg['name'],
                $pkg['description'],
                $tagsById[$pkg['id']] ?? [],
                $readmesById[$pkg['id']] ?? null,
            );
        }

        return $scores;
    }

    #[Route(path: '/admin/spam/nospam', name: 'mark_nospam', defaults: ['_format' => 'html'], methods: ['POST'])]
    public function markSafeAction(Request $req): RedirectResponse
    {
        /** @var string[] $vendors */
        $vendors = array_filter($req->request->all('vendor'), static fn ($vendor) => $vendor !== '' && $vendor !== null);
        if (!$this->isCsrfTokenValid('mark_safe', $req->request->getString('token'))) {
            throw new BadRequestHttpException('Invalid CSRF token');
        }

        $repo = $this->getEM()->getRepository(Vendor::class);
        foreach ($vendors as $vendor) {
            $repo->verify($vendor);
        }

        return $this->redirectToRoute('view_spam');
    }
}
