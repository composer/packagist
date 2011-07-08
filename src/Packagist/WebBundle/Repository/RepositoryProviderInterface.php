<?php

namespace Packagist\WebBundle\Repository;

use Packagist\WebBundle\Repository\Provider\ProviderInterface;

interface RepositoryProviderInterface
{
    public function addProvider(ProviderInterface $provider);

    public function getRepository($url);
}