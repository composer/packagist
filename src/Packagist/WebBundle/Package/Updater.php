<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packagist\WebBundle\Package;

use cebe\markdown\GithubMarkdown;
use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\VcsRepository;
use Composer\Repository\Vcs\GitHubDriver;
use Composer\Repository\InvalidRepositoryException;
use Composer\Util\ErrorHandler;
use Composer\Util\RemoteFilesystem;
use Composer\Config;
use Composer\IO\IOInterface;
use Packagist\WebBundle\Entity\Author;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Tag;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Entity\VersionRepository;
use Packagist\WebBundle\Entity\SuggestLink;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Doctrine\DBAL\Connection;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Updater
{
    const UPDATE_EQUAL_REFS = 1;
    const DELETE_BEFORE = 2;

    /**
     * Doctrine
     * @var RegistryInterface
     */
    protected $doctrine;

    /**
     * Supported link types
     * @var array
     */
    protected $supportedLinkTypes = array(
        'require'     => array(
            'method' => 'getRequires',
            'entity' => 'RequireLink',
        ),
        'conflict'    => array(
            'method' => 'getConflicts',
            'entity' => 'ConflictLink',
        ),
        'provide'     => array(
            'method' => 'getProvides',
            'entity' => 'ProvideLink',
        ),
        'replace'     => array(
            'method' => 'getReplaces',
            'entity' => 'ReplaceLink',
        ),
        'devRequire' => array(
            'method' => 'getDevRequires',
            'entity' => 'DevRequireLink',
        ),
    );

    /**
     * Constructor
     *
     * @param RegistryInterface $doctrine
     */
    public function __construct(RegistryInterface $doctrine)
    {
        $this->doctrine = $doctrine;

        ErrorHandler::register();
    }

    /**
     * Update a project
     *
     * @param \Packagist\WebBundle\Entity\Package $package
     * @param RepositoryInterface $repository the repository instance used to update from
     * @param int $flags a few of the constants of this class
     * @param \DateTime $start
     */
    public function update(IOInterface $io, Config $config, Package $package, RepositoryInterface $repository, $flags = 0, \DateTime $start = null): Package
    {
        $rfs = new RemoteFilesystem($io, $config);

        if (null === $start) {
            $start = new \DateTime();
        }
        $deleteDate = clone $start;
        $deleteDate->modify('-1day');

        $em = $this->doctrine->getManager();
        $apc = extension_loaded('apcu');
        $rootIdentifier = null;

        if ($repository instanceof VcsRepository) {
            $cfg = $repository->getRepoConfig();
            if (isset($cfg['url']) && preg_match('{\bgithub\.com\b}', $cfg['url'])) {
                foreach ($package->getMaintainers() as $maintainer) {
                    if (!($newGithubToken = $maintainer->getGithubToken())) {
                        continue;
                    }

                    $valid = null;
                    if ($apc) {
                        $valid = apcu_fetch('is_token_valid_'.$maintainer->getUsernameCanonical());
                    }

                    if (true !== $valid) {
                        $context = stream_context_create(['http' => ['header' => 'User-agent: packagist-token-check']]);
                        $rate = json_decode(@file_get_contents('https://api.github.com/rate_limit?access_token='.$newGithubToken, false, $context), true);
                        // invalid/outdated token, wipe it so we don't try it again
                        if (!$rate && (strpos($http_response_header[0], '403') || strpos($http_response_header[0], '401'))) {
                            $maintainer->setGithubToken(null);
                            $em->flush($maintainer);
                            continue;
                        }
                    }

                    if ($apc) {
                        apcu_store('is_token_valid_'.$maintainer->getUsernameCanonical(), true, 86400);
                    }

                    $io->setAuthentication('github.com', $newGithubToken, 'x-oauth-basic');
                    break;
                }
            }

            $rootIdentifier = $repository->getDriver()->getRootIdentifier();
        }

        $versions = $repository->getPackages();
        usort($versions, function ($a, $b) {
            $aVersion = $a->getVersion();
            $bVersion = $b->getVersion();
            if ($aVersion === '9999999-dev' || 'dev-' === substr($aVersion, 0, 4)) {
                $aVersion = 'dev';
            }
            if ($bVersion === '9999999-dev' || 'dev-' === substr($bVersion, 0, 4)) {
                $bVersion = 'dev';
            }
            $aIsDev = $aVersion === 'dev' || substr($aVersion, -4) === '-dev';
            $bIsDev = $bVersion === 'dev' || substr($bVersion, -4) === '-dev';

            // push dev versions to the end
            if ($aIsDev !== $bIsDev) {
                return $aIsDev ? 1 : -1;
            }

            // equal versions are sorted by date
            if ($aVersion === $bVersion) {
                return $a->getReleaseDate() > $b->getReleaseDate() ? 1 : -1;
            }

            // the rest is sorted by version
            return version_compare($aVersion, $bVersion);
        });

        $versionRepository = $this->doctrine->getRepository('PackagistWebBundle:Version');

        if ($flags & self::DELETE_BEFORE) {
            foreach ($package->getVersions() as $version) {
                $versionRepository->remove($version);
            }

            $em->flush();
            $em->refresh($package);
        }

        $existingVersions = $versionRepository->getVersionMetadataForUpdate($package);

        $processedVersions = [];
        $lastUpdated = true;
        $lastProcessed = null;
        $idsToMarkUpdated = [];
        foreach ($versions as $version) {
            if ($version instanceof AliasPackage) {
                continue;
            }

            if (isset($processedVersions[strtolower($version->getVersion())])) {
                $io->write('Skipping version '.$version->getPrettyVersion().' (duplicate of '.$processedVersions[strtolower($version->getVersion())]->getPrettyVersion().')', true, IOInterface::VERBOSE);
                continue;
            }
            $processedVersions[strtolower($version->getVersion())] = $version;

            $result = $this->updateInformation($versionRepository, $package, $existingVersions, $version, $flags, $rootIdentifier);
            $lastUpdated = $result['updated'];

            if ($lastUpdated) {
                $em->flush();
                $em->clear();
                $package = $em->merge($package);
            } else {
                $idsToMarkUpdated[] = $result['id'];
            }

            // mark the version processed so we can prune leftover ones
            unset($existingVersions[$result['version']]);
        }

        // mark versions that did not update as updated to avoid them being pruned
        $em->getConnection()->executeUpdate(
            'UPDATE package_version SET updatedAt = :now, softDeletedAt = NULL WHERE id IN (:ids)',
            ['now' => date('Y-m-d H:i:s'), 'ids' => $idsToMarkUpdated],
            ['ids' => Connection::PARAM_INT_ARRAY]
        );

        // remove outdated versions
        foreach ($existingVersions as $version) {
            if (!is_null($version['softDeletedAt']) && new \DateTime($version['softDeletedAt']) < $deleteDate) {
                $versionRepository->remove($versionRepository->findOneById($version['id']));
            } else {
                // set it to be soft-deleted so next update that occurs after deleteDate (1day) if the
                // version is still missing it will be really removed
                $em->getConnection()->executeUpdate(
                    'UPDATE package_version SET softDeletedAt = :now WHERE id = :id',
                    ['now' => date('Y-m-d H:i:s'), 'id' => $version['id']]
                );
            }
        }

        if (preg_match('{^(?:git://|git@|https?://)github.com[:/]([^/]+)/(.+?)(?:\.git|/)?$}i', $package->getRepository(), $match) && $repository instanceof VcsRepository) {
            $this->updateGitHubInfo($rfs, $package, $match[1], $match[2], $repository);
        } else {
            $this->updateReadme($io, $package, $repository);
        }

        $package->setUpdatedAt(new \DateTime);
        $package->setCrawledAt(new \DateTime);
        $em->flush();
        if ($repository->hadInvalidBranches()) {
            throw new InvalidRepositoryException('Some branches contained invalid data and were discarded, it is advised to review the log and fix any issues present in branches');
        }

        return $package;
    }

    /**
     * @return array with keys:
     *                    - updated (whether the version was updated or needs to be marked as updated)
     *                    - id (version id, can be null for newly created versions)
     *                    - version (normalized version from the composer package)
     *                    - object (Version instance if it was updated)
     */
    private function updateInformation(VersionRepository $versionRepo, Package $package, array $existingVersions, PackageInterface $data, $flags, $rootIdentifier)
    {
        $em = $this->doctrine->getManager();
        $version = new Version();

        $normVersion = $data->getVersion();

        $existingVersion = $existingVersions[strtolower($normVersion)] ?? null;
        if ($existingVersion) {
            $source = $existingVersion['source'];
            // update if the right flag is set, or the source reference has changed (re-tag or new commit on branch)
            if ($source['reference'] !== $data->getSourceReference() || ($flags & self::UPDATE_EQUAL_REFS)) {
                $version = $versionRepo->findOneById($existingVersion['id']);
            } else {
                return ['updated' => false, 'id' => $existingVersion['id'], 'version' => strtolower($normVersion), 'object' => null];
            }
        }

        $version->setName($package->getName());
        $version->setVersion($data->getPrettyVersion());
        $version->setNormalizedVersion($normVersion);
        $version->setDevelopment($data->isDev());

        $em->persist($version);

        $descr = $this->sanitize($data->getDescription());
        $version->setDescription($descr);

        // update the package description only for the default branch
        if ($rootIdentifier === null || preg_replace('{dev-|-dev}', '', $version->getVersion()) === $rootIdentifier) {
            $package->setDescription($descr);
        }

        $version->setHomepage($data->getHomepage());
        $version->setLicense($data->getLicense() ?: array());

        $version->setPackage($package);
        $version->setUpdatedAt(new \DateTime);
        $version->setSoftDeletedAt(null);
        $version->setReleasedAt($data->getReleaseDate());

        if ($data->getSourceType()) {
            $source['type'] = $data->getSourceType();
            $source['url'] = $data->getSourceUrl();
            $source['reference'] = $data->getSourceReference();
            $version->setSource($source);
        } else {
            $version->setSource(null);
        }

        if ($data->getDistType()) {
            $dist['type'] = $data->getDistType();
            $dist['url'] = $data->getDistUrl();
            $dist['reference'] = $data->getDistReference();
            $dist['shasum'] = $data->getDistSha1Checksum();
            $version->setDist($dist);
        } else {
            $version->setDist(null);
        }

        if ($data->getType()) {
            $type = $this->sanitize($data->getType());
            $version->setType($type);
            if ($type !== $package->getType()) {
                $package->setType($type);
            }
        }

        $version->setTargetDir($data->getTargetDir());
        $version->setAutoload($data->getAutoload());
        $version->setExtra($data->getExtra());
        $version->setBinaries($data->getBinaries());
        $version->setIncludePaths($data->getIncludePaths());
        $version->setSupport($data->getSupport());

        if ($data->getKeywords()) {
            $keywords = array();
            foreach ($data->getKeywords() as $keyword) {
                $keywords[mb_strtolower($keyword, 'UTF-8')] = $keyword;
            }

            $existingTags = [];
            foreach ($version->getTags() as $tag) {
                $existingTags[mb_strtolower($tag->getName(), 'UTF-8')] = $tag;
            }

            foreach ($keywords as $tagKey => $keyword) {
                if (isset($existingTags[$tagKey])) {
                    unset($existingTags[$tagKey]);
                    continue;
                }

                $tag = Tag::getByName($em, $keyword, true);
                if (!$version->getTags()->contains($tag)) {
                    $version->addTag($tag);
                }
            }

            foreach ($existingTags as $tag) {
                $version->getTags()->removeElement($tag);
            }
        } elseif (count($version->getTags())) {
            $version->getTags()->clear();
        }

        $authorRepository = $this->doctrine->getRepository('PackagistWebBundle:Author');

        $version->getAuthors()->clear();
        if ($data->getAuthors()) {
            foreach ($data->getAuthors() as $authorData) {
                $author = null;

                foreach (array('email', 'name', 'homepage', 'role') as $field) {
                    if (isset($authorData[$field])) {
                        $authorData[$field] = trim($authorData[$field]);
                        if ('' === $authorData[$field]) {
                            $authorData[$field] = null;
                        }
                    } else {
                        $authorData[$field] = null;
                    }
                }

                // skip authors with no information
                if (!isset($authorData['email']) && !isset($authorData['name'])) {
                    continue;
                }

                $author = $authorRepository->findOneBy(array(
                    'email' => $authorData['email'],
                    'name' => $authorData['name'],
                    'homepage' => $authorData['homepage'],
                    'role' => $authorData['role'],
                ));

                if (!$author) {
                    $author = new Author();
                    $em->persist($author);
                }

                foreach (array('email', 'name', 'homepage', 'role') as $field) {
                    if (isset($authorData[$field])) {
                        $author->{'set'.$field}($authorData[$field]);
                    }
                }

                // only update the author timestamp once a month at most as the value is kinda unused
                if ($author->getUpdatedAt() === null || $author->getUpdatedAt()->getTimestamp() < time() - 86400 * 30) {
                    $author->setUpdatedAt(new \DateTime);
                }
                if (!$version->getAuthors()->contains($author)) {
                    $version->addAuthor($author);
                }
            }
        }

        // handle links
        foreach ($this->supportedLinkTypes as $linkType => $opts) {
            $links = array();
            foreach ($data->{$opts['method']}() as $link) {
                $constraint = $link->getPrettyConstraint();
                if (false !== strpos($constraint, ',') && false !== strpos($constraint, '@')) {
                    $constraint = preg_replace_callback('{([><]=?\s*[^@]+?)@([a-z]+)}i', function ($matches) {
                        if ($matches[2] === 'stable') {
                            return $matches[1];
                        }

                        return $matches[1].'-'.$matches[2];
                    }, $constraint);
                }

                $links[$link->getTarget()] = $constraint;
            }

            foreach ($version->{'get'.$linkType}() as $link) {
                // clear links that have changed/disappeared (for updates)
                if (!isset($links[$link->getPackageName()]) || $links[$link->getPackageName()] !== $link->getPackageVersion()) {
                    $version->{'get'.$linkType}()->removeElement($link);
                    $em->remove($link);
                } else {
                    // clear those that are already set
                    unset($links[$link->getPackageName()]);
                }
            }

            foreach ($links as $linkPackageName => $linkPackageVersion) {
                $class = 'Packagist\WebBundle\Entity\\'.$opts['entity'];
                $link = new $class;
                $link->setPackageName($linkPackageName);
                $link->setPackageVersion($linkPackageVersion);
                $version->{'add'.$linkType.'Link'}($link);
                $link->setVersion($version);
                $em->persist($link);
            }
        }

        // handle suggests
        if ($suggests = $data->getSuggests()) {
            foreach ($version->getSuggest() as $link) {
                // clear links that have changed/disappeared (for updates)
                if (!isset($suggests[$link->getPackageName()]) || $suggests[$link->getPackageName()] !== $link->getPackageVersion()) {
                    $version->getSuggest()->removeElement($link);
                    $em->remove($link);
                } else {
                    // clear those that are already set
                    unset($suggests[$link->getPackageName()]);
                }
            }

            foreach ($suggests as $linkPackageName => $linkPackageVersion) {
                $link = new SuggestLink;
                $link->setPackageName($linkPackageName);
                $link->setPackageVersion($linkPackageVersion);
                $version->addSuggestLink($link);
                $link->setVersion($version);
                $em->persist($link);
            }
        } elseif (count($version->getSuggest())) {
            // clear existing suggests if present
            foreach ($version->getSuggest() as $link) {
                $em->remove($link);
            }
            $version->getSuggest()->clear();
        }

        return ['updated' => true, 'id' => $version->getId(), 'version' => strtolower($normVersion), 'object' => $version];
    }

    /**
     * Update the readme for $package from $repository.
     *
     * @param IOInterface $io
     * @param Package $package
     * @param VcsRepository $repository
     */
    private function updateReadme(IOInterface $io, Package $package, VcsRepository $repository)
    {
        try {
            $driver = $repository->getDriver();
            $composerInfo = $driver->getComposerInformation($driver->getRootIdentifier());
            if (isset($composerInfo['readme'])) {
                $readmeFile = $composerInfo['readme'];
            } else {
                $readmeFile = 'README.md';
            }

            $ext = substr($readmeFile, strrpos($readmeFile, '.'));
            if ($ext === $readmeFile) {
                $ext = '.txt';
            }

            switch ($ext) {
                case '.txt':
                    $source = $driver->getFileContent($readmeFile, $driver->getRootIdentifier());
                    if (!empty($source)) {
                        $package->setReadme('<pre>' . htmlspecialchars($source) . '</pre>');
                    }
                    break;

                case '.md':
                    $source = $driver->getFileContent($readmeFile, $driver->getRootIdentifier());
                    $parser = new GithubMarkdown();
                    $readme = $parser->parse($source);

                    if (!empty($readme)) {
                        $package->setReadme($this->prepareReadme($readme));
                    }
                    break;
            }
        } catch (\Exception $e) {
            // we ignore all errors for this minor function
            $io->write(
                'Can not update readme. Error: ' . $e->getMessage(),
                true,
                IOInterface::VERBOSE
            );
        }
    }

    private function updateGitHubInfo(RemoteFilesystem $rfs, Package $package, $owner, $repo, VcsRepository $repository)
    {
        $baseApiUrl = 'https://api.github.com/repos/'.$owner.'/'.$repo;

        $driver = $repository->getDriver();
        if (!$driver instanceof GitHubDriver) {
            return;
        }

        $repoData = $driver->getRepoData();

        try {
            $opts = ['http' => ['header' => ['Accept: application/vnd.github.v3.html']]];
            $readme = $rfs->getContents('github.com', $baseApiUrl.'/readme', false, $opts);
        } catch (\Exception $e) {
            if (!$e instanceof \Composer\Downloader\TransportException || $e->getCode() !== 404) {
                return;
            }
            // 404s just mean no readme present so we proceed with the rest
        }

        if (!empty($readme)) {
            $package->setReadme($this->prepareReadme($readme, true, $owner, $repo));
        }

        if (!empty($repoData['language'])) {
            $package->setLanguage($repoData['language']);
        }
        if (isset($repoData['stargazers_count'])) {
            $package->setGitHubStars($repoData['stargazers_count']);
        }
        if (isset($repoData['subscribers_count'])) {
            $package->setGitHubWatches($repoData['subscribers_count']);
        }
        if (isset($repoData['network_count'])) {
            $package->setGitHubForks($repoData['network_count']);
        }
        if (isset($repoData['open_issues_count'])) {
            $package->setGitHubOpenIssues($repoData['open_issues_count']);
        }
    }

    /**
     * Prepare the readme by stripping elements and attributes that are not supported .
     *
     * @param string $readme
     * @param bool $isGithub
     * @param null $owner
     * @param null $repo
     * @return string
     */
    private function prepareReadme($readme, $isGithub = false, $owner = null, $repo = null)
    {
        $elements = array(
            'p',
            'br',
            'small',
            'strong', 'b',
            'em', 'i',
            'strike',
            'sub', 'sup',
            'ins', 'del',
            'ol', 'ul', 'li',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'dl', 'dd', 'dt',
            'pre', 'code', 'samp', 'kbd',
            'q', 'blockquote', 'abbr', 'cite',
            'table', 'thead', 'tbody', 'th', 'tr', 'td',
            'a', 'span',
            'img',
        );

        $attributes = array(
            'img.src', 'img.title', 'img.alt', 'img.width', 'img.height', 'img.style',
            'a.href', 'a.target', 'a.rel', 'a.id',
            'td.colspan', 'td.rowspan', 'th.colspan', 'th.rowspan',
            '*.class'
        );

        // detect base path if the github readme is located in a subfolder like docs/README.md
        $basePath = '';
        if ($isGithub && preg_match('{^<div id="readme" [^>]+?data-path="([^"]+)"}', $readme, $match) && false !== strpos($match[1], '/')) {
            $basePath = dirname($match[1]);
        }
        if ($basePath) {
            $basePath .= '/';
        }

        $config = \HTMLPurifier_Config::createDefault();
        $config->set('HTML.AllowedElements', implode(',', $elements));
        $config->set('HTML.AllowedAttributes', implode(',', $attributes));
        $config->set('Attr.EnableID', true);
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $purifier = new \HTMLPurifier($config);
        $readme = $purifier->purify($readme);

        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $readme);

        // Links can not be trusted, mark them nofollow and convert relative to absolute links
        $links = $dom->getElementsByTagName('a');
        foreach ($links as $link) {
            $link->setAttribute('rel', 'nofollow noindex noopener external');
            if ('#' === substr($link->getAttribute('href'), 0, 1)) {
                $link->setAttribute('href', '#user-content-'.substr($link->getAttribute('href'), 1));
            } elseif ('mailto:' === substr($link->getAttribute('href'), 0, 7)) {
                // do nothing
            } elseif ($isGithub && false === strpos($link->getAttribute('href'), '//')) {
                $link->setAttribute(
                    'href',
                    'https://github.com/'.$owner.'/'.$repo.'/blob/HEAD/'.$basePath.$link->getAttribute('href')
                );
            }
        }

        if ($isGithub) {
            // convert relative to absolute images
            $images = $dom->getElementsByTagName('img');
            foreach ($images as $img) {
                if (false === strpos($img->getAttribute('src'), '//')) {
                    $img->setAttribute(
                        'src',
                        'https://raw.github.com/'.$owner.'/'.$repo.'/HEAD/'.$basePath.$img->getAttribute('src')
                    );
                }
            }
        }

        // remove first page element if it's a <h1> or <h2>, because it's usually
        // the project name or the `README` string which we don't need
        $first = $dom->getElementsByTagName('body')->item(0);
        if ($first) {
            $first = $first->childNodes->item(0);
        }

        if ($first && ('h1' === $first->nodeName || 'h2' === $first->nodeName)) {
            $first->parentNode->removeChild($first);
        }

        $readme = $dom->saveHTML();
        $readme = substr($readme, strpos($readme, '<body>')+6);
        $readme = substr($readme, 0, strrpos($readme, '</body>'));

        return str_replace("\r\n", "\n", $readme);
    }

    private function sanitize($str)
    {
        // remove escape chars
        $str = preg_replace("{\x1B(?:\[.)?}u", '', $str);

        return preg_replace("{[\x01-\x1A]}u", '', $str);
    }
}
