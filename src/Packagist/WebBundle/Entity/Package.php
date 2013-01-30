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
use Composer\IO\NullIO;
use Composer\Factory;
use Composer\Repository\VcsRepository;
use Composer\Repository\RepositoryManager;

/**
 * @ORM\Entity(repositoryClass="Packagist\WebBundle\Entity\PackageRepository")
 * @ORM\Table(
 *     name="package",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="name_idx", columns={"name"})},
 *     indexes={
 *         @ORM\Index(name="indexed_idx",columns={"indexedAt"}),
 *         @ORM\Index(name="crawled_idx",columns={"crawledAt"}),
 *         @ORM\Index(name="dumped_idx",columns={"dumpedAt"})
 *     }
 * )
 * @Assert\Callback(methods={"isPackageUnique"})
 * @Assert\Callback(methods={"isRepositoryValid"}, groups={"Update", "Default"})
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
     * @Assert\NotBlank(groups={"Update", "Default"})
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

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $dumpedAt;

    /**
     * @ORM\Column(type="boolean")
     */
    private $autoUpdated = false;

    /**
     * @ORM\Column(type="boolean", options={"default"=false})
     */
    private $updateFailureNotified = false;

    private $entityRepository;
    private $router;

    /**
     * @var \Composer\Repository\Vcs\VcsDriverInterface
     */
    private $vcsDriver = true;

    public function __construct()
    {
        $this->versions = new ArrayCollection();
        $this->createdAt = new \DateTime;
    }

    public function toArray()
    {
        $versions = array();
        foreach ($this->getVersions() as $version) {
            /** @var $version Version */
            $versions[$version->getVersion()] = $version->toArray();
        }
        $maintainers = array();
        foreach ($this->getMaintainers() as $maintainer) {
            /** @var $maintainer Maintainer */
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
        // vcs driver was not nulled which means the repository was not set/modified and is still valid
        if (true === $this->vcsDriver && null !== $this->getName()) {
            return;
        }

        $property = 'repository';
        $driver = $this->vcsDriver;
        if (!is_object($driver)) {
            if (preg_match('{//.+@}', $this->repository)) {
                $context->addViolationAtSubPath($property, 'URLs with user@host are not supported, use a read-only public URL', array(), null);
            } else {
                $context->addViolationAtSubPath($property, 'No valid/supported repository was found at the given URL', array(), null);
            }
            return;
        }
        try {
            $information = $driver->getComposerInformation($driver->getRootIdentifier());

            if (false === $information) {
                $context->addViolationAtSubPath($property, 'No composer.json was found in the '.$driver->getRootIdentifier().' branch.', array(), null);
                return;
            }

            if (empty($information['name'])) {
                $context->addViolationAtSubPath($property, 'The package name was not found in the composer.json, make sure there is a name present.', array(), null);
                return;
            }

            if (!preg_match('{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$}i', $information['name'])) {
                $context->addViolationAtSubPath($property, 'The package name '.$information['name'].' is invalid, it should have a vendor name, a forward slash, and a package name. The vendor and package name can be words separated by -, . or _. The complete name should match "[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*".', array(), null);
                return;
            }

            if (preg_match('{[A-Z]}', $information['name'])) {
                $suggestName = preg_replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $information['name']);
                $suggestName = strtolower($suggestName);

                $context->addViolationAtSubPath($property, 'The package name '.$information['name'].' is invalid, it should not contain uppercase characters. We suggest using '.$suggestName.' instead.');
                return;
            }
        } catch (\Exception $e) {
            $context->addViolationAtSubPath($property, 'We had problems parsing your composer.json file, the parser reports: '.$e->getMessage(), array(), null);
        }
    }

    public function setEntityRepository($repository)
    {
        $this->entityRepository = $repository;
    }

    public function setRouter($router)
    {
        $this->router = $router;
    }

    public function isPackageUnique(ExecutionContext $context)
    {
        try {
            if ($this->entityRepository->findOneByName($this->name)) {
                $context->addViolationAtSubPath('repository', 'A package with the name <a href="'.$this->router->generate('view_package', array('name' => $this->name)).'">'.$this->name.'</a> already exists.', array(), null);
            }
        } catch (\Doctrine\ORM\NoResultException $e) {}
    }

    /**
     * Get id
     *
     * @return string
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
     * @return string
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
     * @return string
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set createdAt
     *
     * @param \DateTime $createdAt
     */
    public function setCreatedAt($createdAt)
    {
        $this->createdAt = $createdAt;
    }

    /**
     * Get createdAt
     *
     * @return \DateTime
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
        $this->vcsDriver = null;

        // prevent local filesystem URLs
        if (preg_match('{^(\.|[a-z]:|/)}i', $repository)) {
            return;
        }

        $this->repository = $repository;

        // avoid user@host URLs
        if (preg_match('{//.+@}', $repository)) {
            return;
        }

        try {
            $config = Factory::createConfig();
            $repository = new VcsRepository(array('url' => $repository), new NullIO(), $config);

            $driver = $this->vcsDriver = $repository->getDriver();
            if (!$driver) {
                return;
            }
            $information = $driver->getComposerInformation($driver->getRootIdentifier());
            if (!isset($information['name'])) {
                throw new \RuntimeException('No name found in composer.json');
            }
            if (null === $this->getName()) {
                $this->setName($information['name']);
            }
        } catch (\Exception $e) {
        }
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
     * @param \Packagist\WebBundle\Entity\Version $versions
     */
    public function addVersions(Version $versions)
    {
        $this->versions[] = $versions;
    }

    /**
     * Get versions
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getVersions()
    {
        return $this->versions;
    }

    /**
     * Set updatedAt
     *
     * @param \DateTime $updatedAt
     */
    public function setUpdatedAt($updatedAt)
    {
        $this->updatedAt = $updatedAt;
        $this->setUpdateFailureNotified(false);
    }

    /**
     * Get updatedAt
     *
     * @return \DateTime
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }

    /**
     * Set crawledAt
     *
     * @param \DateTime|null $crawledAt
     */
    public function setCrawledAt($crawledAt)
    {
        $this->crawledAt = $crawledAt;
    }

    /**
     * Get crawledAt
     *
     * @return \DateTime
     */
    public function getCrawledAt()
    {
        return $this->crawledAt;
    }

    /**
     * Set indexedAt
     *
     * @param \DateTime $indexedAt
     */
    public function setIndexedAt($indexedAt)
    {
        $this->indexedAt = $indexedAt;
    }

    /**
     * Get indexedAt
     *
     * @return \DateTime
     */
    public function getIndexedAt()
    {
        return $this->indexedAt;
    }

    /**
     * Set dumpedAt
     *
     * @param \DateTime $dumpedAt
     */
    public function setDumpedAt($dumpedAt)
    {
        $this->dumpedAt = $dumpedAt;
    }

    /**
     * Get dumpedAt
     *
     * @return \DateTime
     */
    public function getDumpedAt()
    {
        return $this->dumpedAt;
    }

    /**
     * Add maintainers
     *
     * @param \Packagist\WebBundle\Entity\User $maintainer
     */
    public function addMaintainer(User $maintainer)
    {
        $this->maintainers[] = $maintainer;
    }

    /**
     * Get maintainers
     *
     * @return \Doctrine\Common\Collections\Collection
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

    /**
     * Set autoUpdated
     *
     * @param Boolean $autoUpdated
     */
    public function setAutoUpdated($autoUpdated)
    {
        $this->autoUpdated = $autoUpdated;
    }

    /**
     * Get autoUpdated
     *
     * @return Boolean
     */
    public function isAutoUpdated()
    {
        return $this->autoUpdated;
    }

    /**
     * Set updateFailureNotified
     *
     * @param Boolean $updateFailureNotified
     */
    public function setUpdateFailureNotified($updateFailureNotified)
    {
        $this->updateFailureNotified = $updateFailureNotified;
    }

    /**
     * Get updateFailureNotified
     *
     * @return Boolean
     */
    public function isUpdateFailureNotified()
    {
        return $this->updateFailureNotified;
    }
}
