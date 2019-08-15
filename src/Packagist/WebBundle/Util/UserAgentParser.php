<?php declare(strict_types=1);

namespace Packagist\WebBundle\Util;

class UserAgentParser
{
    /** @var ?string */
    private $composerVersion;
    /** @var ?string */
    private $phpVersion;
    /** @var ?string */
    private $os;
    /** @var ?string */
    private $httpVersion;
    /** @var ?bool */
    private $ci;

    public function __construct(?string $userAgent)
    {
        if ($userAgent && preg_match('#^Composer/(?P<composer>[a-z0-9.+-]+) \((?P<os>\S+).*?; PHP (?P<php>[0-9.]+)[^;]*(?:; (?P<http>streams|curl [0-9.]+)[^;]*)?(?P<ci>; CI)?#i', $userAgent, $matches)) {
            if ($matches['composer'] === 'source' || preg_match('{^[a-f0-9]{40}$}', $matches['composer'])) {
                $matches['composer'] = 'pre-1.8.5';
            }
            $this->composerVersion = preg_replace('{\+[a-f0-9]{40}}', '', $matches['composer']);
            $this->phpVersion = $matches['php'];
            $this->os = $matches['os'];
            $this->httpVersion = $matches['http'] ?? null;
            $this->ci = (bool) ($matches['ci'] ?? null);
        }
    }

    public function getComposerVersion(): ?string
    {
        return $this->composerVersion;
    }

    public function getPhpVersion(): ?string
    {
        return $this->phpVersion;
    }

    public function getOs(): ?string
    {
        return $this->os;
    }

    public function getHttpVersion(): ?string
    {
        return $this->httpVersion;
    }

    public function getCI(): ?bool
    {
        return $this->ci;
    }
}
