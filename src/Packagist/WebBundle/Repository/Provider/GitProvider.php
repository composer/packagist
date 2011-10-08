<?php

namespace Packagist\WebBundle\Repository\Provider;

use Packagist\WebBundle\Repository\Repository\GitRepository;

class GitProvider implements ProviderInterface
{
    public function getRepository($url)
    {
        if($this->supports($url)){
            return new GitRepository($url);
        }
    }

    public function supports($url)
    {
        // TODO adjust
        return preg_match('#^(?:https?|git)://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $url, $match);
    }
}
