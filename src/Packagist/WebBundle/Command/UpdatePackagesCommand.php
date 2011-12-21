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

namespace Packagist\WebBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Finder\Finder;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Entity\Tag;
use Packagist\WebBundle\Entity\Author;
use Packagist\WebBundle\Repository\Repository\RepositoryInterface;
use Composer\Package\Version\VersionParser;
use Composer\Repository\VcsRepository;
use Composer\Package\PackageInterface;
use Composer\Repository\RepositoryManager;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UpdatePackagesCommand extends ContainerAwareCommand
{
    protected $versionParser;

    protected $supportedLinkTypes = array(
        'require'   => 'RequireLink',
        'conflict'  => 'ConflictLink',
        'provide'   => 'ProvideLink',
        'replace'   => 'ReplaceLink',
        'recommend' => 'RecommendLink',
        'suggest'   => 'SuggestLink',
    );

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:update')
            ->setDefinition(array(
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a re-crawl of all packages'),
                new InputArgument('package', InputArgument::OPTIONAL, 'Package name to update (implicitly enables --force)'),
            ))
            ->setDescription('Updates packages')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $verbose = $input->getOption('verbose');
        $force = $input->getOption('force');
        $package = $input->getArgument('package');

        $doctrine = $this->getContainer()->get('doctrine');
        $logger = $this->getContainer()->get('logger');

        $this->versionParser = new VersionParser;

        if ($package) {
            $packages = array($doctrine->getRepository('PackagistWebBundle:Package')->findOneByName($package));
        } elseif ($force) {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->findAll();
        } else {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getStalePackages();
        }

        $start = new \DateTime();

        $repositoryManager = new RepositoryManager;
        $repositoryManager->setRepositoryClass('composer', 'Composer\Repository\ComposerRepository');
        $repositoryManager->setRepositoryClass('vcs', 'Composer\Repository\VcsRepository');
        $repositoryManager->setRepositoryClass('pear', 'Composer\Repository\PearRepository');
        $repositoryManager->setRepositoryClass('package', 'Composer\Repository\PackageRepository');

        foreach ($packages as $package) {
            if ($verbose) {
                $output->writeln('Importing '.$package->getRepository());
            }

            try {
                // clear versions to force a clean reloading if --force is enabled
                if ($force) {
                    $versionRepo = $doctrine->getRepository('PackagistWebBundle:Version');
                    foreach ($package->getVersions() as $version) {
                        $versionRepo->remove($version);
                    }

                    $doctrine->getEntityManager()->flush();
                    $doctrine->getEntityManager()->refresh($package);
                }

                $repository = new VcsRepository(array('url' => $package->getRepository()));
                if ($verbose) {
                    $repository->setDebug(true);
                }
                $versions = $repository->getPackages();

                usort($versions, function ($a, $b) {
                    return version_compare($a->getVersion(), $b->getVersion());
                });

                foreach ($versions as $version) {
                    if ($verbose) {
                        $output->writeln('Storing '.$version->getPrettyVersion().' ('.$version->getVersion().')');
                    }

                    $this->updateInformation($output, $doctrine, $package, $version);
                    $doctrine->getEntityManager()->flush();
                }

                // remove outdated -dev versions
                foreach ($package->getVersions() as $version) {
                    if ($version->getDevelopment() && $version->getUpdatedAt() < $start) {
                        if ($verbose) {
                            $output->writeln('Deleting stale version: '.$version->getVersion());
                        }
                        $doctrine->getRepository('PackagistWebBundle:Version')->remove($version);
                    }
                }

                $package->setUpdatedAt(new \DateTime);
                $package->setCrawledAt(new \DateTime);
                $doctrine->getEntityManager()->flush();
            } catch (\Exception $e) {
                $output->writeln('<error>Exception: '.$e->getMessage().', skipping package '.$package->getName().'.</error>');
            }
        }
    }

    private function updateInformation(OutputInterface $output, RegistryInterface $doctrine, $package, PackageInterface $data)
    {
        $em = $doctrine->getEntityManager();
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

        $version->getAuthors()->clear();
        if ($data->getAuthors()) {
            foreach ($data->getAuthors() as $authorData) {
                $author = null;
                // skip authors with no information
                if (empty($authorData['email']) && empty($authorData['name'])) {
                    continue;
                }

                if (!empty($authorData['email'])) {
                    $author = $doctrine->getRepository('PackagistWebBundle:Author')->findOneByEmail($authorData['email']);
                }

                if (!$author && !empty($authorData['homepage'])) {
                    $author = $doctrine->getRepository('PackagistWebBundle:Author')->findOneBy(array(
                        'name' => $authorData['name'],
                        'homepage' => $authorData['homepage']
                    ));
                }

                if (!$author && !empty($authorData['name'])) {
                    $author = $doctrine->getRepository('PackagistWebBundle:Author')->findOneByNameAndPackage($authorData['name'], $package);
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
