<?php

namespace Packagist\WebBundle\Repository\Repository;

interface RepositoryInterface
{
    /**
     * Returns the decoded composer.json file.
     */
    public function getComposerFile($hash);
}