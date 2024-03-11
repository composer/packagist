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

use App\Entity\PackageLink;
use App\Model\ProviderManager;
use App\Security\RecaptchaHelper;
use Composer\Pcre\Preg;
use Symfony\Component\HttpFoundation\RequestStack;
use Composer\Repository\PlatformRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

class PackagistExtension extends AbstractExtension
{
    private ProviderManager $providerManager;
    private RecaptchaHelper $recaptchaHelper;

    public function __construct(ProviderManager $providerManager, RecaptchaHelper $recaptchaHelper)
    {
        $this->providerManager = $providerManager;
        $this->recaptchaHelper = $recaptchaHelper;
    }

    public function getTests(): array
    {
        return [
            new TwigTest('existing_package', [$this, 'packageExistsTest']),
            new TwigTest('existing_provider', [$this, 'providerExistsTest']),
            new TwigTest('numeric', [$this, 'numericTest']),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('prettify_source_reference', [$this, 'prettifySourceReference']),
            new TwigFilter('gravatar_hash', [$this, 'generateGravatarHash']),
            new TwigFilter('vendor', [$this, 'getVendor']),
            new TwigFilter('sort_links', [$this, 'sortLinks']),
        ];
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('requires_recaptcha', [$this, 'requiresRecaptcha']),
        ];
    }

    public function getName(): string
    {
        return 'packagist';
    }

    public function getVendor(string $packageName): string
    {
        return Preg::replace('{/.*$}', '', $packageName);
    }

    public function numericTest(mixed $val): bool
    {
        if (!is_string($val) && !is_int($val)) {
            return false;
        }

        return ctype_digit((string) $val);
    }

    public function packageExistsTest(mixed $package): bool
    {
        if (!is_string($package)) {
            return false;
        }

        if (!Preg::isMatch('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $package)) {
            return false;
        }

        return $this->providerManager->packageExists($package);
    }

    public function providerExistsTest(mixed $package): bool
    {
        if (!is_string($package)) {
            return false;
        }

        return $this->providerManager->packageIsProvided($package);
    }

    public function prettifySourceReference(string $sourceReference): string
    {
        if (Preg::isMatch('/^[a-f0-9]{40}$/', $sourceReference)) {
            return substr($sourceReference, 0, 7);
        }

        return $sourceReference;
    }

    public function generateGravatarHash(string $email): string
    {
        return md5(strtolower($email));
    }

    /**
     * @param PackageLink[] $links
     * @return PackageLink[]
     */
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

    public function requiresRecaptcha(): bool
    {
        $context = $this->recaptchaHelper->buildContext();

        return $this->recaptchaHelper->requiresRecaptcha($context);
    }
}
