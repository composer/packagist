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
    const UPDATE_TAGS = 1;
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
     * @param PackageInterface $package
     * @param RepositoryInterface $repository the repository instance used to update from
     * @param int $flags a few of the constants of this class
     * @param DateTime $start
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
        $em = $this->doctrine->getEntityManager();

        usort($versions, function ($a, $b) {
            return version_compare($a->getVersion(), $b->getVersion());
        });

        $versionRepository = $this->doctrine->getRepository('PackagistWebBundle:Version');

        if ($flags & self::DELETE_BEFORE) {
            foreach ($package->getVersions() as $version) {
                $versionRepository->remove($version);
            }

            $em->flush();
            $em->refresh($package);
        }

        foreach ($versions as $version) {
            if ($version instanceof AliasPackage) {
                continue;
            }

            if (preg_match($blacklist, $version->getName().' '.$version->getPrettyVersion())) {
                continue;
            }

            $this->updateInformation($package, $version, $flags);
            $em->flush();
        }

        // remove outdated -dev versions
        foreach ($package->getVersions() as $version) {
            if ($version->getDevelopment() && $version->getUpdatedAt() < $pruneDate) {
                $versionRepository->remove($version);
            }
        }

        $package->setUpdatedAt(new \DateTime);
        $package->setCrawledAt(new \DateTime);
        $em->flush();
    }

    private function updateInformation(Package $package, PackageInterface $data, $flags)
    {
        $em = $this->doctrine->getEntityManager();
        $version = new Version();

        $version->setName($package->getName());
        $version->setNormalizedVersion($data->getVersion());

        // check if we have that version yet
        foreach ($package->getVersions() as $existingVersion) {
            if ($existingVersion->equals($version)) {
                // avoid updating newer versions, in case two branches have the same version in their composer.json
                if ($existingVersion->getReleasedAt() > $data->getReleaseDate()) {
                    return;
                }
                if ($existingVersion->getDevelopment() || ($flags & self::UPDATE_TAGS)) {
                    $version = $existingVersion;
                    break;
                }
                return;
            }
        }

        $version->setVersion($data->getPrettyVersion());
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
        }

        if ($data->getDistType()) {
            $dist['type'] = $data->getDistType();
            $dist['url'] = $data->getDistUrl();
            $dist['reference'] = $data->getDistReference();
            $dist['shasum'] = $data->getDistSha1Checksum();
            $version->setDist($dist);
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

        $version->getTags()->clear();
        if ($data->getKeywords()) {
            foreach ($data->getKeywords() as $keyword) {
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
                // skip authors with no information
                if (empty($authorData['email']) && empty($authorData['name'])) {
                    continue;
                }

                if (!empty($authorData['email'])) {
                    $author = $authorRepository->findOneByEmail($authorData['email']);
                }

                if (!$author && !empty($authorData['homepage'])) {
                    $author = $authorRepository->findOneBy(array(
                        'name' => $authorData['name'],
                        'homepage' => $authorData['homepage']
                    ));
                }

                if (!$author && !empty($authorData['name'])) {
                    $author = $authorRepository->findOneByNameAndPackage($authorData['name'], $package);
                }

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
                $links[$link->getTarget()] = $link->getPrettyConstraint();
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
        }

        if (!$package->getVersions()->contains($version)) {
            $package->addVersions($version);
        }
    }
}
