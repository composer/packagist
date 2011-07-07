<?php

namespace Packagist\WebBundle\Repository\Repository;

interface RepositoryInterface
{
    /**
     * Return an array of all composer files (by tag).
     *
     * The array shall be in the form of $unique_identifier => $composer_file
     */
    public function getAllComposerFiles();
}