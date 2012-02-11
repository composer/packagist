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

use Composer\Package\PackageInterface;
use Composer\Repository\VcsRepository;
use Composer\IO\NullIO;
use Packagist\WebBundle\Entity\Author;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\Tag;
use Packagist\WebBundle\Entity\Version;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Updater
{
    /**
     * Doctrine
     * @var RegistryInterface
     */
    protected $doctrine;

    /**
     * Start
     * @var DateTime
     */
    protected $start;

    /**
     * Supported link types
     * @var array
     */
    protected $supportedLinkTypes = array(
        'require'   => 'RequireLink',
        'conflict'  => 'ConflictLink',
        'provide'   => 'ProvideLink',
        'replace'   => 'ReplaceLink',
        'recommend' => 'RecommendLink',
        'suggest'   => 'SuggestLink',
    );

    /**
     * Constructor
     * 
     * @param RegistryInterface $doctrine
     * @param \DateTime $start
     */
    public function __construct(RegistryInterface $doctrine, \DateTime $start = null)
    {
        $this->doctrine = $doctrine;
        $this->start = null !== $start ? $start : new \DateTime();
    }

    /**
     * Update a project
     *
     * @param PackageInterface $package
     * @param boolean $clearExistingVersions
     */
    public function update(Package $package, $clearExistingVersions = false)
    {
        $repository = new VcsRepository(array('url' => $package->getRepository()), new NullIO());
        $versions = $repository->getPackages();
        $em = $this->doctrine->getEntityManager();
        
        usort($versions, function ($a, $b) {
            return version_compare($a->getVersion(), $b->getVersion());
        });

        $versionRepository = $this->doctrine->getRepository('PackagistWebBundle:Version');
        
        if ($clearExistingVersions) {
            foreach ($package->getVersions() as $version) {
                $versionRepo->remove($version);
            }
        
            $em->flush();
            $em->refresh($package);
        }

       foreach ($versions as $version) {
            $this->updateInformation($package, $version);
            $em->flush();
        }
        
        // remove outdated -dev versions
        foreach ($package->getVersions() as $version) {
            if ($version->getDevelopment() && $version->getUpdatedAt() < $this->start) {
                $versionRepository->remove($version);
            }
        }
        
        $package->setUpdatedAt(new \DateTime);
        $package->setCrawledAt(new \DateTime);
        $em->flush();
    }

    private function updateInformation(Package $package, PackageInterface $data)
    {
        $em = $this->doctrine->getEntityManager();
        $version = new Version();
    
        $version->setName($package->getName());
        $version->setNormalizedVersion(preg_replace('{-dev$}i', '', $data->getVersion()));
    
        // check if we have that version yet
        foreach ($package->getVersions() as $existingVersion) {
            if ($existingVersion->equals($version)) {
                // avoid updating newer versions, in case two branches have the same version in their composer.json
                if ($existingVersion->getReleasedAt() > $data->getReleaseDate()) {
                    return;
                }
                if ($existingVersion->getDevelopment()) {
                    $version = $existingVersion;
                    break;
                }
                return;
            }
        }
    
        $version->setVersion($data->getPrettyVersion());
        $version->setDevelopment(substr($data->getVersion(), -4) === '-dev');
    
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
                $version->addTag(Tag::getByName($em, $keyword, true));
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
    
                foreach (array('email', 'name', 'homepage') as $field) {
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
    
        foreach ($this->supportedLinkTypes as $linkType => $linkEntity) {
            $links = array();
            foreach ($data->{'get'.$linkType.'s'}() as $link) {
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
                $class = 'Packagist\WebBundle\Entity\\'.$linkEntity;
                $link = new $class;
                $link->setPackageName($linkPackageName);
                $link->setPackageVersion($linkPackageVersion);
                $version->{'add'.$linkType.'Link'}($link);
                $link->setVersion($version);
                $em->persist($link);
            }
        }
    
        if (!$package->getVersions()->contains($version)) {
            $package->addVersions($version);
        }
    }
}
