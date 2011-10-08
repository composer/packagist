<?php

namespace Packagist\WebBundle\Repository\Provider;

interface ProviderInterface
{
    /**
     * Returns whether the provider supports the URL
     * @param string $url
     */
    public function supports($url);

    /**
     * Get the repository for the URL.
     * This method is expected to return null if the URL is not supported.
     *
     * @param string $url
     */
    public function getRepository($url);
}
