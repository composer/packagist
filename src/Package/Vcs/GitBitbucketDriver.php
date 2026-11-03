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

namespace App\Package\Vcs;

/**
 * Falls back to HttpGitDriver instead of the git binary
 */
class GitBitbucketDriver extends \Composer\Repository\Vcs\GitBitbucketDriver
{
    protected function setupFallbackDriver(string $url): void
    {
        $this->fallbackDriver = new HttpGitDriver(['url' => $url] + $this->repoConfig, $this->io, $this->config, $this->httpDownloader, $this->process);
        $this->fallbackDriver->initialize();
    }
}
