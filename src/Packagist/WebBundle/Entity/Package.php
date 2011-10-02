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

namespace Packagist\WebBundle\Entity;

use Packagist\WebBundle\Repository\RepositoryProviderInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ExecutionContext;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * @ORM\Entity(repositoryClass="Packagist\WebBundle\Entity\PackageRepository")
 * @ORM\Table(
 *     name="package",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="name_idx", columns={"name"})}
 * )
 * @Assert\Callback(methods={"isPackageUnique","isRepositoryValid"})
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Package
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * Unique package name
     *
     * @ORM\Column()
     */
    private $name;

    /**
     * @ORM\Column(nullable="true")
     */
    private $type;

    /**
     * @ORM\Column(type="text", nullable="true")
     */
    private $description;

    /**
     * @ORM\OneToMany(targetEntity="Packagist\WebBundle\Entity\Version", mappedBy="package")
     */
    private $versions;

    /**
     * @ORM\ManyToMany(targetEntity="User", inversedBy="packages")
     * @ORM\JoinTable(name="maintainers_packages")
     */
    private $maintainers;

    /**
     * @ORM\Column()
     * @Assert\NotBlank()
     */
    private $repository;

    // dist-tags / rel or runtime?

    /**
     * @ORM\Column(type="datetime")
     */
    private $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable="true")
     */
    private $updatedAt;

    /**
     * @ORM\Column(type="datetime", nullable="true")
     */
    private $crawledAt;

    public function __construct()
    {
        $this->versions = new ArrayCollection();
        $this->createdAt = new \DateTime;
    }

    public function toArray()
    {
        $versions = array();
        foreach ($this->getVersions() as $version) {
            $versions[$version->getVersion()] = $version->toArray();
        }
        $maintainers = array();
        foreach ($this->getMaintainers() as $maintainer) {
            $maintainers[] = $maintainer->toArray();
        }
        $data = array(
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'dist-tags' => array(),
            'maintainers' => $maintainers,
            'versions' => $versions,
            'type' => $this->getType(),
        );
        return $data;
    }

    public function setRepositoryProvider(RepositoryProviderInterface $provider)
    {
        $this->repositoryProvider = $provider;
    }

    public function isRepositoryValid(ExecutionContext $context)
    {
        $propertyPath = $context->getPropertyPath() . '.repository';
        $context->setPropertyPath($propertyPath);

        $repo = $this->repositoryClass;
        if (!$repo) {
            $context->addViolation('No valid/supported repository was found at the given URL', array(), null);
            return;
        }
        try {
            $information = $repo->getComposerInformation($repo->getRootIdentifier());
        } catch (\UnexpectedValueException $e) {}
        // TODO use more specialized exception for repos

        if (!isset($information['name']) || !$information['name']) {
            $context->addViolation('The package name was not found, your composer.json file must be invalid or missing in your master branch/trunk. Maybe the URL you entered has a typo.', array(), null);
            return;
        }

        if (!preg_match('{^[a-z0-9_-]+/[a-z0-9_-]+$}i', $information['name'])) {
            $context->addViolation('The package name '.$information['name'].' is invalid, it should have a vendor name, a forward slash, and a package name, matching <em>[a-z0-9_-]+/[a-z0-9_-]+</em>.', array(), null);
            return;
        }
    }

    public function isPackageUnique(ExecutionContext $context)
    {
        // TODO check for uniqueness of package name
    }

    /**
     * Get id
     *
     * @return string $id
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name
     *
     * @param string $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Get name
     *
     * @return string $name
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Set description
     *
     * @param text $description
     */
    public function setDescription($description)
    {
        $this->description = $description;
    }

    /**
     * Get description
     *
     * @return text $description
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set createdAt
     *
     * @param datetime $createdAt
     */
    public function setCreatedAt($createdAt)
    {
        $this->createdAt = $createdAt;
    }

    /**
     * Get createdAt
     *
     * @return datetime $createdAt
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * Set repository
     *
     * @param string $repository
     */
    public function setRepository($repository)
    {
        $this->repository = $repository;

        try {
            $this->repositoryClass = $repo = $this->repositoryProvider->getRepository($this->repository);
            if (!$repo) {
                return;
            }
            $information = $repo->getComposerInformation($repo->getRootIdentifier());
            $this->setName($information['name']);
        } catch (\UnexpectedValueException $e) {}
        // TODO use more specialized exception for repos
    }

    /**
     * Get repository
     *
     * @return string $repository
     */
    public function getRepository()
    {
        return $this->repository;
    }

    /**
     * Add versions
     *
     * @param Packagist\WebBundle\Entity\Version $versions
     */
    public function addVersions(Version $versions)
    {
        $this->versions[] = $versions;
    }

    /**
     * Get versions
     *
     * @return string $versions
     */
    public function getVersions()
    {
        return $this->versions;
    }

    /**
     * Set updatedAt
     *
     * @param datetime $updatedAt
     */
    public function setUpdatedAt($updatedAt)
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * Get updatedAt
     *
     * @return datetime $updatedAt
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }

    /**
     * Set crawledAt
     *
     * @param datetime $crawledAt
     */
    public function setCrawledAt($crawledAt)
    {
        $this->crawledAt = $crawledAt;
    }

    /**
     * Get crawledAt
     *
     * @return datetime $crawledAt
     */
    public function getCrawledAt()
    {
        return $this->crawledAt;
    }

    /**
     * Add maintainers
     *
     * @param Packagist\WebBundle\Entity\User $maintainer
     */
    public function addMaintainer(User $maintainer)
    {
        $this->maintainers[] = $maintainer;
    }

    /**
     * Get maintainers
     *
     * @return Doctrine\Common\Collections\Collection $maintainers
     */
    public function getMaintainers()
    {
        return $this->maintainers;
    }

    /**
     * Set type
     *
     * @param text $type
     */
    public function setType($type)
    {
        $this->type = $type;
    }

    /**
     * Get type
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }
}