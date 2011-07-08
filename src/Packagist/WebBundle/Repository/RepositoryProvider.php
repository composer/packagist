<?php

namespace Packagist\WebBundle\Repository;

use Packagist\WebBundle\Repository\Provider\ProviderInterface;

class RepositoryProvider implements RepositoryProviderInterface
{
    protected $providers = array();

    public function addProvider(ProviderInterface $provider)
    {
        $this->providers[] = $provider;
    }

    public function getRepository($url)
    {
        foreach ($this->providers as $provider){
            if($provider->supports($url)){
                return $provider->getRepository($url);
            }
        }
    }
}