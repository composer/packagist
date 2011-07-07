<?php

namespace Packagist\WebBundle\Repository\Repository;

interface RepositoryInterface
{
    /**
     * Return an array of all composer files (by tag).
     */
    public function getAllComposerFiles();
}