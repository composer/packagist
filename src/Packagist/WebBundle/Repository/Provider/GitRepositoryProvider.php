<?php

namespace Packagist\WebBundle\Repository\Provider;

use Packagist\WebBundle\Repository\Repository\GitRepository;

class GitRepositoryProvider implements RepositoryProviderInterface
{
    public function getRepository($url)
    {
        return new GitRepository($url);
    }

    public function supports($url)
    {
        return preg_match('#^(?:https?|git)://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $repo, $match);
    }
}