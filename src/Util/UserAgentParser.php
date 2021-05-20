<?php declare(strict_types=1);

namespace App\Util;

class UserAgentParser
{
    private ?string $composerVersion = null;
    private ?string $phpVersion = null;
    private ?string $platformPhpVersion = null;
    private ?string $os = null;
    private ?string $httpVersion = null;
    private ?bool $ci = null;

    public function __construct(?string $userAgent)
    {
        if ($userAgent && preg_match('#^Composer/(?P<composer>[a-z0-9.+-]+) \((?P<os>[^\s;]+)[^;]*?; [^;]*?; (?P<engine>HHVM|PHP) (?P<php>[0-9.]+)[^;]*(?:; (?P<http>streams|curl \d+\.\d+)[^;)]*)?(?:; Platform-PHP (?P<platform_php>[0-9.]+)[^;]*)?(?P<ci>; CI)?#i', $userAgent, $matches)) {
            if ($matches['composer'] === 'source' || preg_match('{^[a-f0-9]{40}$}', $matches['composer'])) {
                $matches['composer'] = 'pre-1.8.5';
            }
            $this->composerVersion = preg_replace('{\+[a-f0-9]{40}}', '', $matches['composer']);
            $this->phpVersion = (strtolower($matches['engine']) === 'hhvm' ? 'hhvm-' : '') . $matches['php'];
            $this->platformPhpVersion = !empty($matches['platform_php']) ? (strtolower($matches['engine']) === 'hhvm' ? 'hhvm-' : '') . $matches['platform_php'] : null;
            $this->os = preg_replace('{^cygwin_nt-.*}', 'cygwin', strtolower($matches['os']));
            $this->httpVersion = !empty($matches['http']) ? strtolower($matches['http']) : null;
            $this->ci = (bool) ($matches['ci'] ?? null);
        }
    }

    public function getComposerVersion(): ?string
    {
        return $this->composerVersion;
    }

    public function getComposerMajorVersion(): ?string
    {
        if (!$this->composerVersion) {
            return null;
        }

        if ($this->composerVersion === 'pre-1.8.5') {
            return '1';
        }

        return substr($this->composerVersion, 0, 1);
    }

    public function getPhpVersion(): ?string
    {
        return $this->phpVersion;
    }

    public function getPhpMinorVersion(): ?string
    {
        if (null === $this->phpVersion) {
            return null;
        }

        if (substr($this->phpVersion, 0, 5) === 'hhvm-') {
            return 'hhvm';
        }

        $version = preg_replace('{^(\d+\.\d+).*}', '$1', $this->phpVersion);

        return $this->isValidMinorVersion($version) ? $version : null;
    }

    public function getPhpMinorPlatformVersion(): ?string
    {
        if (null === $this->platformPhpVersion) {
            return null;
        }

        if (substr($this->platformPhpVersion, 0, 5) === 'hhvm-') {
            return 'hhvm';
        }

        $version = preg_replace('{^(\d+\.\d+).*}', '$1', $this->platformPhpVersion);

        return $this->isValidMinorVersion($version) ? $version : null;
    }

    private function isValidMinorVersion(string $version): bool
    {
        if ($version === 'hhvm') {
            return true;
        }

        // TODO update for php 9
        if (preg_match('{^(5\.[23456]|7\.[01234]|8\.[0123456])$}', $version)) {
            return true;
        }

        return false;
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
