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

use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Repository\VcsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\ExecutionContextInterface;

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
 * @Assert\Callback(methods={"isVendorWritable"})
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
     * @ORM\Column(type="string", nullable=true)
     */
    private $language;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $readme;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_stars")
     */
    private $gitHubStars;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_watches")
     */
    private $gitHubWatches;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_forks")
     */
    private $gitHubForks;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_open_issues")
     */
    private $gitHubOpenIssues;

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
     * @var bool
     * @ORM\Column(type="boolean")
     */
    private $abandoned = false;

    /**
     * @var string
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private $replacementPackage;

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
    private $vcsDriverError;

    /**
     * @var array lookup table for versions
     */
    private $cachedVersions;

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
            /** @var $maintainer User */
            $maintainers[] = $maintainer->toArray();
        }
        $data = array(
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'time' => $this->getCreatedAt()->format('c'),
            'maintainers' => $maintainers,
            'versions' => $versions,
            'type' => $this->getType(),
            'repository' => $this->getRepository(),
        );

        if ($this->isAbandoned()) {
            $data['abandoned'] = $this->getReplacementPackage() ?: true;
        }

        return $data;
    }

    public function isRepositoryValid(ExecutionContextInterface $context)
    {
        // vcs driver was not nulled which means the repository was not set/modified and is still valid
        if (true === $this->vcsDriver && null !== $this->getName()) {
            return;
        }

        $property = 'repository';
        $driver = $this->vcsDriver;
        if (!is_object($driver)) {
            if (preg_match('{https?://.+@}', $this->repository)) {
                $context->addViolationAt($property, 'URLs with user@host are not supported, use a read-only public URL', array(), null);
            } elseif (is_string($this->vcsDriverError)) {
                $context->addViolationAt($property, 'Uncaught Exception: '.$this->vcsDriverError, array(), null);
            } else {
                $context->addViolationAt($property, 'No valid/supported repository was found at the given URL', array(), null);
            }
            return;
        }
        try {
            $information = $driver->getComposerInformation($driver->getRootIdentifier());

            if (false === $information) {
                $context->addViolationAt($property, 'No composer.json was found in the '.$driver->getRootIdentifier().' branch.', array(), null);
                return;
            }

            if (empty($information['name'])) {
                $context->addViolationAt($property, 'The package name was not found in the composer.json, make sure there is a name present.', array(), null);
                return;
            }

            if (!preg_match('{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$}i', $information['name'])) {
                $context->addViolationAt($property, 'The package name '.$information['name'].' is invalid, it should have a vendor name, a forward slash, and a package name. The vendor and package name can be words separated by -, . or _. The complete name should match "[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*".', array(), null);
                return;
            }

            if (preg_match('{[A-Z]}', $information['name'])) {
                $suggestName = preg_replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $information['name']);
                $suggestName = strtolower($suggestName);

                $context->addViolationAt($property, 'The package name '.$information['name'].' is invalid, it should not contain uppercase characters. We suggest using '.$suggestName.' instead.');
                return;
            }
        } catch (\Exception $e) {
            $context->addViolationAt($property, 'We had problems parsing your composer.json file, the parser reports: '.$e->getMessage(), array(), null);
        }
        if (null === $this->getName()) {
            $context->addViolationAt($property, 'An unexpected error has made our parser fail to find a package name in your repository, if you think this is incorrect please try again', array(), null);
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

    public function isPackageUnique(ExecutionContextInterface $context)
    {
        try {
            if ($this->entityRepository->findOneByName($this->name)) {
                $context->addViolationAt('repository', 'A package with the name <a href="'.$this->router->generate('view_package', array('name' => $this->name)).'">'.$this->name.'</a> already exists.', array(), null);
            }
        } catch (\Doctrine\ORM\NoResultException $e) {}
    }

    public function isVendorWritable(ExecutionContextInterface $context)
    {
        try {
            $vendor = $this->getVendor();
            if ($vendor && $this->entityRepository->isVendorTaken($vendor, reset($this->maintainers))) {
                $context->addViolationAt(
                    'repository',
                    'The vendor is already taken by someone else. '
                        . 'You may ask them to add your package and give you maintainership access. '
                        . 'The packages already in that vendor namespace can be found at '
                        . '<a href="'.$this->router->generate('view_vendor', array('vendor' => $vendor)).'">'.$vendor.'</a>',
                    array(),
                    null
                );
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
     * Set language
     *
     * @param string $language
     */
    public function setLanguage($language)
    {
        $this->language = $language;
    }

    /**
     * Get language
     *
     * @return string
     */
    public function getLanguage()
    {
        return $this->language;
    }

    /**
     * Set readme
     *
     * @param string $readme
     */
    public function setReadme($readme)
    {
        $this->readme = $readme;
    }

    /**
     * Get readme
     *
     * @return string
     */
    public function getReadme()
    {
        return $this->readme;
    }

    /**
     * @param int $val
     */
    public function setGitHubStars($val)
    {
        $this->gitHubStars = $val;
    }

    /**
     * @return int
     */
    public function getGitHubStars()
    {
        return $this->gitHubStars;
    }

    /**
     * @param int $val
     */
    public function setGitHubWatches($val)
    {
        $this->gitHubWatches = $val;
    }

    /**
     * @return int
     */
    public function getGitHubWatches()
    {
        return $this->gitHubWatches;
    }

    /**
     * @param int $val
     */
    public function setGitHubForks($val)
    {
        $this->gitHubForks = $val;
    }

    /**
     * @return int
     */
    public function getGitHubForks()
    {
        return $this->gitHubForks;
    }

    /**
     * @param int $val
     */
    public function setGitHubOpenIssues($val)
    {
        $this->gitHubOpenIssues = $val;
    }

    /**
     * @return int
     */
    public function getGitHubOpenIssues()
    {
        return $this->gitHubOpenIssues;
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
    public function setRepository($repoUrl)
    {
        $this->vcsDriver = null;

        // prevent local filesystem URLs
        if (preg_match('{^(\.|[a-z]:|/)}i', $repoUrl)) {
            return;
        }

        $repoUrl = preg_replace('{^git@github.com:}i', 'https://github.com/', $repoUrl);
        $this->repository = $repoUrl;

        // avoid user@host URLs
        if (preg_match('{https?://.+@}', $repoUrl)) {
            return;
        }

        try {
            $io = new NullIO();
            $config = Factory::createConfig();
            $io->loadConfiguration($config);
            $repository = new VcsRepository(array('url' => $this->repository), $io, $config);

            $driver = $this->vcsDriver = $repository->getDriver();
            if (!$driver) {
                return;
            }
            $information = $driver->getComposerInformation($driver->getRootIdentifier());
            if (!isset($information['name'])) {
                return;
            }
            if (null === $this->getName()) {
                $this->setName($information['name']);
            }
            if ($driver instanceof GitHubDriver) {
                $this->repository = $driver->getRepositoryUrl();
            }
        } catch (\Exception $e) {
            $this->vcsDriverError = '['.get_class($e).'] '.$e->getMessage();
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
     * @param Version $versions
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

    public function getVersion($normalizedVersion)
    {
        if (null === $this->cachedVersions) {
            $this->cachedVersions = array();
            foreach ($this->getVersions() as $version) {
                $this->cachedVersions[strtolower($version->getNormalizedVersion())] = $version;
            }
        }

        if (isset($this->cachedVersions[strtolower($normalizedVersion)])) {
            return $this->cachedVersions[strtolower($normalizedVersion)];
        }
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
     * @param User $maintainer
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

    /**
     * @return boolean
     */
    public function isAbandoned()
    {
        return $this->abandoned;
    }

    /**
     * @param boolean $abandoned
     */
    public function setAbandoned($abandoned)
    {
        $this->abandoned = $abandoned;
    }

    /**
     * @return string
     */
    public function getReplacementPackage()
    {
        return $this->replacementPackage;
    }

    /**
     * @param string $replacementPackage
     */
    public function setReplacementPackage($replacementPackage)
    {
        $this->replacementPackage = $replacementPackage;
    }
}
