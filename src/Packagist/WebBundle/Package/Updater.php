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

use Composer\Package\AliasPackage;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\InvalidRepositoryException;
use Composer\Util\ErrorHandler;
use Packagist\WebBundle\Entity\Author;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Tag;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Entity\SuggestLink;
use Symfony\Bridge\Doctrine\RegistryInterface;

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
    public function update(Package $package, RepositoryInterface $repository, $flags = 0, \DateTime $start = null)
    {
        $blacklist = '{^symfony/symfony (2.0.[456]|dev-charset|dev-console)}i';

        if (null === $start) {
            $start = new \DateTime();
        }
        $pruneDate = clone $start;
        $pruneDate->modify('-8days');

        $versions = $repository->getPackages();
        $em = $this->doctrine->getManager();

        if ($repository->hadInvalidBranches()) {
            throw new InvalidRepositoryException('Some branches contained invalid data and were discarded, it is advised to review the log and fix any issues present in branches');
        }

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

        $lastUpdated = true;
        foreach ($versions as $version) {
            if ($version instanceof AliasPackage) {
                continue;
            }

            if (preg_match($blacklist, $version->getName().' '.$version->getPrettyVersion())) {
                continue;
            }

            $lastUpdated = $this->updateInformation($package, $version, $flags);
            if ($lastUpdated) {
                $em->flush();
            }
        }

        if (!$lastUpdated) {
            $em->flush();
        }

        // remove outdated versions
        foreach ($package->getVersions() as $version) {
            if ($version->getUpdatedAt() < $pruneDate) {
                $versionRepository->remove($version);
            }
        }

        $package->setUpdatedAt(new \DateTime);
        $package->setCrawledAt(new \DateTime);
        $em->flush();
    }

    private function updateInformation(Package $package, PackageInterface $data, $flags)
    {
        $em = $this->doctrine->getManager();
        $version = new Version();

        $normVersion = $data->getVersion();

        $existingVersion = $package->getVersion($normVersion);
        if ($existingVersion) {
            $source = $existingVersion->getSource();
            // update if the right flag is set, or the source reference has changed (re-tag or new commit on branch)
            if ($source['reference'] !== $data->getSourceReference() || ($flags & self::UPDATE_EQUAL_REFS)) {
                $version = $existingVersion;
            } else {
                // mark it updated to avoid it being pruned
                $existingVersion->setUpdatedAt(new \DateTime);

                return false;
            }
        }

        $version->setName($package->getName());
        $version->setVersion($data->getPrettyVersion());
        $version->setNormalizedVersion($normVersion);
        $version->setDevelopment($data->isDev());

        $em->persist($version);

        $version->setDescription($data->getDescription());
        $package->setDescription($data->getDescription());
        $version->setHomepage($data->getHomepage());
        $version->setLicense($data->getLicense() ?: array());

        $version->setPackage($package);
        $version->setUpdatedAt(new \DateTime);
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
            $version->setType($data->getType());
            if ($data->getType() && $data->getType() !== $package->getType()) {
                $package->setType($data->getType());
            }
        }

        $version->setTargetDir($data->getTargetDir());
        $version->setAutoload($data->getAutoload());
        $version->setExtra($data->getExtra());
        $version->setBinaries($data->getBinaries());
        $version->setIncludePaths($data->getIncludePaths());
        $version->setSupport($data->getSupport());

        $version->getTags()->clear();
        if ($data->getKeywords()) {
            $keywords = array();
            foreach ($data->getKeywords() as $keyword) {
                $keywords[mb_strtolower($keyword, 'UTF-8')] = $keyword;
            }
            foreach ($keywords as $keyword) {
                $tag = Tag::getByName($em, $keyword, true);
                if (!$version->getTags()->contains($tag)) {
                    $version->addTag($tag);
                }
            }
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

                $author->setUpdatedAt(new \DateTime);
                if (!$version->getAuthors()->contains($author)) {
                    $version->addAuthor($author);
                }
                if (!$author->getVersions()->contains($version)) {
                    $author->addVersion($version);
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

        if (!$package->getVersions()->contains($version)) {
            $package->addVersions($version);
        }

        return true;
    }
}
