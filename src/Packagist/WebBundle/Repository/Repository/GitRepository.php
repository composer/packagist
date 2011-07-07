<?php

namespace Packagist\WebBundle\Repository\Repository;

class GitRepository implements RepositoryInterface
{
    protected $owner;
    protected $repository;

    public function __construct($url)
    {
        preg_match('#^(?:https?|git)://github\.com/([^/]+)/(.+?)(?:\.git)?$#', $url, $match);
        $this->owner = $match[1];
        $this->repository = $match[2];
    }

    protected function getRepoData()
    {
        return json_decode(file_get_contents('http://github.com/api/v2/json/repos/show/'.$this->owner.'/'.$this->repository), true);
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
        if(!$repoData = $this->getRepoData()) {
            throw new \Exception();
        }

        $files = array();

        $tagsData = json_decode(file_get_contents('http://github.com/api/v2/json/repos/show/'.$this->owner.'/'.$this->repository.'/tags'), true);
        foreach ($tagsData['tags'] as $tag => $hash) {
            if($file = json_decode(file_get_contents('https://raw.github.com/'.$this->owner.'/'.$this->repository.'/'.$hash.'/composer.json'), true)) {

                if(!isset($file['time'])) {
                    $commit = json_decode(file_get_contents('http://github.com/api/v2/json/commits/show/'.$this->owner.'/'.$this->repository.'/'.$tag), true);
                    $file['time'] = $commit['commit']['committed_date'];
                }

                $file['download'] = $this->getDist($tag);

                $files[$tag] = $file;
            }
        }

        return $files;
    }
}