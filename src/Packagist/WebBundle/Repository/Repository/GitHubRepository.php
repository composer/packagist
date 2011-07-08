<?php

namespace Packagist\WebBundle\Repository\Repository;

class GitHubRepository implements RepositoryInterface
{
    protected $owner;
    protected $repository;
    protected $data;

    public function __construct($url)
    {
        preg_match('#^(?:https?|git)://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $url, $match);
        $this->owner = $match[1];
        $this->repository = $match[2];
    }

    protected function getRepoData()
    {
        if (null === $this->data) {
            $url = 'http://github.com/api/v2/json/repos/show/'.$this->owner.'/'.$this->repository;
            $this->data = json_decode(@file_get_contents($url), true);
            if (!$this->data) {
                throw new \UnexpectedValueException('Failed to download from '.$url);
            }
        }
        return $this->data;
    }

    public function getType()
    {
        return 'git';
    }

    public function getUrl()
    {
        return 'http://github.com/'.$this->owner.'/'.$this->repository.'.git';
    }

    protected function getDist($tag)
    {
        $repoData = $this->getRepoData();
        if ($repoData['repository']['has_downloads']) {
            return 'https://github.com/'.$this->owner.'/'.$this->repository.'/zipball/'.$tag;
        } else {
            // TODO clone the repo and build/host a zip ourselves. Not sure if this can happen, but it'll be needed for non-GitHub repos anyway
        }
    }

    public function getAllComposerFiles()
    {
        $repoData = $this->getRepoData();

        $files = array();

        $tagsData = json_decode(file_get_contents('http://github.com/api/v2/json/repos/show/'.$this->owner.'/'.$this->repository.'/tags'), true);
        foreach ($tagsData['tags'] as $tag => $hash) {
            if ($file = json_decode(file_get_contents('https://raw.github.com/'.$this->owner.'/'.$this->repository.'/'.$hash.'/composer.json'), true)) {
                if (!isset($file['time'])) {
                    $commit = json_decode(file_get_contents('http://github.com/api/v2/json/commits/show/'.$this->owner.'/'.$this->repository.'/'.$tag), true);
                    $file['time'] = $commit['commit']['committed_date'];
                }

                // TODO parse $data['version'] w/ composer version parser, if no match, ignore the tag

                $file['download'] = $this->getDist($tag);

                $files[$tag] = $file;
            }
        }

        return $files;
    }
}