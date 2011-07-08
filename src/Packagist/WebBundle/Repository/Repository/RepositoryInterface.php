<?php

namespace Packagist\WebBundle\Repository\Repository;

interface RepositoryInterface
{
    /**
     * Return an array of all composer files (by tag).
     */
    public function getAllComposerFiles();

    /**
     * Return the URL of the Repository
     */
    public function getUrl();

    /**
     * Return the type of the repository
     */
    public function getType();
}