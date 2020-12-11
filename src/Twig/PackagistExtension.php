<?php

namespace App\Twig;

use App\Model\ProviderManager;
use App\Security\RecaptchaHelper;
use Symfony\Component\HttpFoundation\RequestStack;
use Composer\Repository\PlatformRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;
use Twig\TwigTest;

class PackagistExtension extends AbstractExtension
{
    /**
     * @var ProviderManager
     */
    private $providerManager;
    /** @var RecaptchaHelper */
    private $recaptchaHelper;
    /** @var RequestStack */
    private $requestStack;

    public function __construct(ProviderManager $providerManager, RecaptchaHelper $recaptchaHelper, RequestStack $requestStack)
    {
        $this->providerManager = $providerManager;
        $this->recaptchaHelper = $recaptchaHelper;
        $this->requestStack = $requestStack;
    }

    public function getTests()
    {
        return array(
            new TwigTest('existing_package', [$this, 'packageExistsTest']),
            new TwigTest('existing_provider', [$this, 'providerExistsTest']),
            new TwigTest('numeric', [$this, 'numericTest']),
        );
    }

    public function getFilters()
    {
        return array(
            new TwigFilter('prettify_source_reference', [$this, 'prettifySourceReference']),
            new TwigFilter('gravatar_hash', [$this, 'generateGravatarHash']),
            new TwigFilter('vendor', [$this, 'getVendor']),
            new TwigFilter('sort_links', [$this, 'sortLinks']),
        );
    }

    public function getFunctions()
    {
        return array(
            new TwigFunction('requires_recaptcha', [$this, 'requiresRecaptcha']),
        );
    }

    public function getName()
    {
        return 'packagist';
    }

    public function getVendor(string $packageName): string
    {
        return preg_replace('{/.*$}', '', $packageName);
    }

    public function numericTest($val)
    {
        return ctype_digit((string) $val);
    }

    public function packageExistsTest($package)
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $package)) {
            return false;
        }

        return $this->providerManager->packageExists($package);
    }

    public function providerExistsTest($package)
    {
        return $this->providerManager->packageIsProvided($package);
    }

    public function prettifySourceReference($sourceReference)
    {
        if (preg_match('/^[a-f0-9]{40}$/', $sourceReference)) {
            return substr($sourceReference, 0, 7);
        }

        return $sourceReference;
    }

    public function generateGravatarHash($email)
    {
        return md5(strtolower($email));
    }

    public function sortLinks(array $links)
    {
        usort($links, function ($a, $b) {
            $aPlatform = preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $a->getPackageName());
            $bPlatform = preg_match(PlatformRepository::PLATFORM_PACKAGE_REGEX, $b->getPackageName());

            if ($aPlatform !== $bPlatform) {
                return $aPlatform ? -1 : 1;
            }

            if (preg_match('{^php(?:-64bit|-ipv6|-zts|-debug)?$}iD', $a->getPackageName())) {
                return -1;
            }
            if (preg_match('{^php(?:-64bit|-ipv6|-zts|-debug)?$}iD', $b->getPackageName())) {
                return 1;
            }

            return $a->getPackageName() <=> $b->getPackageName();
        });

        return $links;
    }

    public function requiresRecaptcha(?string $username): bool
    {
        return $this->recaptchaHelper->requiresRecaptcha($this->requestStack->getCurrentRequest()->getClientIp(), $username);
    }
}
