<?php

namespace Packagist\WebBundle\Repository;

interface RepositoryProviderInterface
{
    public function addProvider(RepositoryProviderInterface $provider);

    public function getRepository($url);
}