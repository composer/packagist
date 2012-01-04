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

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ExecutionContext;
use Doctrine\Common\Collections\ArrayCollection;
use Composer\Repository\VcsRepository;
use Composer\Repository\RepositoryManager;

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
     * @ORM\Column(nullable=true)
     */
    private $type;

    /**
     * @ORM\Column(type="text", nullable=true)
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
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $updatedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $crawledAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $indexedAt;

    private $entityRepository;
    private $repositoryClass;

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
            'maintainers' => $maintainers,
            'versions' => $versions,
            'type' => $this->getType(),
            'repository' => $this->getRepository()
        );
        return $data;
    }

    public function isRepositoryValid(ExecutionContext $context)
    {
        $propertyPath = $context->getPropertyPath() . '.repository';
        $context->setPropertyPath($propertyPath);

        $repo = $this->repositoryClass;
        if (!$repo) {
            if (preg_match('{//.+@}', $this->repository)) {
                $context->addViolation('URLs with user@host are not supported, use a read-only public URL', array(), null);
            } else {
                $context->addViolation('No valid/supported repository was found at the given URL', array(), null);
            }
            return;
        }
        try {
            $information = $repo->getComposerInformation($repo->getRootIdentifier());

            if (!isset($information['name']) || !$information['name']) {
                $context->addViolation('The package name was not found in the composer.json, make sure there is a name present.', array(), null);
                return;
            }

            if (!preg_match('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}i', $information['name'])) {
                $context->addViolation('The package name '.$information['name'].' is invalid, it should have a vendor name, a forward slash, and a package name, matching <em>[a-z0-9_.-]+/[a-z0-9_.-]+</em>.', array(), null);
                return;
            }
        } catch (\UnexpectedValueException $e) {
            $context->addViolation('We had problems parsing your composer.json file, the parser reports: '.$e->getMessage(), array(), null);
        }
    }

    public function setEntityRepository($repository)
    {
        $this->entityRepository = $repository;
    }

    public function isPackageUnique(ExecutionContext $context)
    {
        try {
            if ($this->entityRepository->findOneByName($this->name)) {
                $propertyPath = $context->getPropertyPath() . '.repository';
                $context->setPropertyPath($propertyPath);
                $context->addViolation('A package with the name '.$this->name.' already exists.', array(), null);
            }
        } catch (\Doctrine\ORM\NoResultException $e) {}
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
     * Get vendor prefix
     *
     * @return string
     */
    public function getVendor()
    {
        return preg_replace('{/.*$}', '', $this->name);
    }

    /**
     * Get package name without vendor
     *
     * @return string
     */
    public function getPackageName()
    {
        return preg_replace('{^[^/]*/}', '', $this->name);
    }

    /**
     * Set description
     *
     * @param string $description
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

        // avoid user@host URLs
        if (preg_match('{//.+@}', $repository)) {
            return;
        }

        $repositoryManager = new RepositoryManager;
        $repositoryManager->setRepositoryClass('composer', 'Composer\Repository\ComposerRepository');
        $repositoryManager->setRepositoryClass('vcs', 'Composer\Repository\VcsRepository');
        $repositoryManager->setRepositoryClass('pear', 'Composer\Repository\PearRepository');
        $repositoryManager->setRepositoryClass('package', 'Composer\Repository\PackageRepository');

        try {
            $repository = new VcsRepository(array('url' => $repository));

            $repo = $this->repositoryClass = $repository->getDriver();
            if (!$repo) {
                return;
            }
            $information = $repo->getComposerInformation($repo->getRootIdentifier());
            $this->setName($information['name']);
        } catch (\UnexpectedValueException $e) {}
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
     * Set indexedAt
     *
     * @param datetime $indexedAt
     */
    public function setIndexedAt($indexedAt)
    {
        $this->indexedAt = $indexedAt;
    }

    /**
     * Get indexedAt
     *
     * @return datetime $indexedAt
     */
    public function getIndexedAt()
    {
        return $this->indexedAt;
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
     * @param string $type
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