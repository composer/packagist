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
class GitHubDriver extends \Composer\Repository\Vcs\GitHubDriver
{
    protected function setupGitDriver(string $url): void
    {
        // the parent keeps this flag private, it comes from the same repo config
        if (isset($this->repoConfig['allow-git-fallback']) && $this->repoConfig['allow-git-fallback'] === false) {
            throw new \RuntimeException('Fallback to git driver disabled');
        }

        $this->gitDriver = new HttpGitDriver(['url' => $url] + $this->repoConfig, $this->io, $this->config, $this->httpDownloader, $this->process);
        $this->gitDriver->initialize();
    }
}
