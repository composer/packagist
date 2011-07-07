<?php

namespace Packagist\WebBundle\Repository\Repository;

interface RepositoryInterface
{
    /**
     * Return an array of all composer files (by tag).
     *
     * The array shall be in the form of $uniqid => $composer_file
     */
    public function getAllComposerFiles();

    //TODO: This doesn't seem very clean.
    public function getDist($uniqid);

    /**
     * Return the URL of the Repository
     */
    public function getUrl();
}