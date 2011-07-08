<?php

namespace Packagist\WebBundle\Repository\Repository;

interface RepositoryInterface
{
    /**
     * Return the composer.json file information
     *
     * @param string $identifier Any identifier to a specific branch/tag/commit
     * @return array containing all infos from the composer.json file
     */
    function getComposerInformation($identifier);

    /**
     * Return list of branches in the repository
     *
     * @return array Branch names as keys, identifiers as values
     */
    function getBranches();

    /**
     * Return list of tags in the repository
     *
     * @return array Tag names as keys, identifiers as values
     */
    function getTags();

    /**
     * Return the URL of the repository
     *
     * @param string $identifier Any identifier to a specific branch/tag/commit
     * @return array With type, url and shasum properties.
     */
    function getDist($identifier);

    /**
     * Return the URL of the repository
     *
     * @return string
     */
    function getUrl();

    /**
     * Return the type of the repository
     *
     * @return string
     */
    function getType();
}