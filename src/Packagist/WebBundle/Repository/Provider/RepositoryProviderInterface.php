<?php

namespace Packagist\WebBundle\Repository\Provider;

interface RepositoryProviderInterface
{
    /**
     * Does the provider support the URL?
     * @param string $url
     */
    public function supports($url);

    /**
     * Get the repository for the URL
     * @param string $url
     *
     */
    public function getRepository($url);
}