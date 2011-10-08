<?php

namespace Packagist\WebBundle\Repository\Provider;

use Packagist\WebBundle\Repository\Repository\GitHubRepository;

class GitHubProvider implements ProviderInterface
{
    public function getRepository($url)
    {
        if($this->supports($url)){
            return new GitHubRepository($url);
        }
    }

    public function supports($url)
    {
        return preg_match('#^(?:https?|git)://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $url, $match);
    }
}
