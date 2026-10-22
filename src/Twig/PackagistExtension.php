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

namespace App\Twig;

use App\Entity\OrganizationRepository;
use App\Entity\PackageLink;
use App\Entity\UserRepository;
use App\Entity\VersionSummary;
use App\Model\ProviderManager;
use App\Security\RecaptchaHelper;
use App\Security\Voter\PackageActions;
use Composer\Pcre\Preg;
use Composer\Repository\PlatformRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;
use Twig\Attribute\AsTwigTest;

class PackagistExtension
{
    private ProviderManager $providerManager;
    private RecaptchaHelper $recaptchaHelper;
    private Security $security;
    private OrganizationRepository $organizationRepo;
    private UserRepository $userRepo;

    public function __construct(ProviderManager $providerManager, RecaptchaHelper $recaptchaHelper, OrganizationRepository $organizationRepo, UserRepository $userRepo, Security $security)
    {
        $this->providerManager = $providerManager;
        $this->recaptchaHelper = $recaptchaHelper;
        $this->security = $security;
        $this->organizationRepo = $organizationRepo;
        $this->userRepo = $userRepo;
    }

    #[AsTwigFilter(name: 'vendor')]
    public function getVendor(string $packageName): string
    {
        return Preg::replace('{/.*$}', '', $packageName);
    }

    #[AsTwigTest(name: 'numeric')]
    public function numericTest(mixed $val): bool
    {
        if (!\is_string($val) && !\is_int($val)) {
            return false;
        }

        return ctype_digit((string) $val);
    }

    #[AsTwigTest(name: 'existing_package')]
    public function packageExistsTest(mixed $package): bool
    {
        if (!\is_string($package)) {
            return false;
        }

        if (!Preg::isMatch('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $package)) {
            return false;
        }

        return $this->providerManager->packageExists($package);
    }

    #[AsTwigTest(name: 'existing_organization')]
    public function organizationExistsTest(mixed $slug): bool
    {
        if (!\is_string($slug)) {
            return false;
        }

        if (!Preg::isMatch('/^[a-z0-9-]+$/', $slug)) {
            return false;
        }

        return $this->organizationRepo->slugExists($slug, false);
    }

    #[AsTwigTest(name: 'existing_user')]
    public function userExistsTest(mixed $username): bool
    {
        if (!\is_string($username) || $username === '') {
            return false;
        }

        return $this->userRepo->usernameExists($username);
    }

    #[AsTwigTest(name: 'existing_provider')]
    public function providerExistsTest(mixed $package): bool
    {
        if (!\is_string($package)) {
            return false;
        }

        return $this->providerManager->packageIsProvided($package);
    }

    /**
     * Human-readable JSON for debug views (job payloads/results, ..). Invalid UTF-8 is substituted
     * rather than thrown on, so one bad byte in a captured exception message cannot 500 the page.
     * Deliberately not is_safe, so autoescaping still applies.
     */
    #[AsTwigFilter(name: 'json_pretty')]
    public function jsonPretty(mixed $value): string
    {
        return json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR);
    }

    #[AsTwigFilter(name: 'prettify_source_reference')]
    public function prettifySourceReference(string $sourceReference): string
    {
        if (Preg::isMatch('/^[a-f0-9]{40}$/', $sourceReference)) {
            return substr($sourceReference, 0, 7);
        }

        return $sourceReference;
    }

    #[AsTwigFilter(name: 'gravatar_hash')]
    public function generateGravatarHash(string $email): string
    {
        return hash('sha256', strtolower($email));
    }

    /**
     * @param PackageLink[] $links
     *
     * @return PackageLink[]
     */
    #[AsTwigFilter(name: 'sort_links')]
    public function sortLinks(array $links): array
    {
        usort($links, static function (PackageLink $a, PackageLink $b) {
            $aPlatform = PlatformRepository::isPlatformPackage($a->getPackageName());
            $bPlatform = PlatformRepository::isPlatformPackage($b->getPackageName());

            if ($aPlatform !== $bPlatform) {
                return $aPlatform ? -1 : 1;
            }

            if (Preg::isMatch('{^php(?:-64bit|-ipv6|-zts|-debug)?$}iD', $a->getPackageName())) {
                return -1;
            }
            if (Preg::isMatch('{^php(?:-64bit|-ipv6|-zts|-debug)?$}iD', $b->getPackageName())) {
                return 1;
            }

            return $a->getPackageName() <=> $b->getPackageName();
        });

        return $links;
    }

    #[AsTwigFunction(name: 'requires_recaptcha')]
    public function requiresRecaptcha(): bool
    {
        $context = $this->recaptchaHelper->buildContext();

        return $this->recaptchaHelper->requiresRecaptcha($context);
    }

    #[AsTwigFilter(name: 'version_deletion_title')]
    public function deletionTitle(VersionSummary $version): ?string
    {
        return $version->getDeletionTitle(
            $this->security->isGranted(PackageActions::AdminDeleteVersion->value, $version->getPackage())
        );
    }
}
