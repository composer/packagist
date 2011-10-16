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
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Finder\Finder;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Entity\Tag;
use Packagist\WebBundle\Entity\Author;
use Packagist\WebBundle\Repository\Repository\RepositoryInterface;
use Composer\Package\Version\VersionParser;

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
            ->setName('pkg:update')
            ->setDefinition(array(
            ))
            ->setDescription('Updates packages')
            ->setHelp(<<<EOF

EOF
            )
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $verbose = $input->getOption('verbose');
        $doctrine = $this->getContainer()->get('doctrine');

        $logger = $this->getContainer()->get('logger');
        $provider = $this->getContainer()->get('packagist.repository_provider');

        $this->versionParser = new VersionParser;

        $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getStalePackages();

        foreach ($packages as $package) {
            $repository = $provider->getRepository($package->getRepository());

            if (!$repository) {
                $output->writeln('<error>Unsupported repository: '.$package->getRepository().'</error>');
                continue;
            }

            if ($verbose) {
                $output->writeln('Importing '.$repository->getUrl());
            }

            try {
                foreach ($repository->getTags() as $tag => $identifier) {
                    if ($repository->hasComposerFile($identifier) && $this->validateTag($tag)) {
                        $data = $repository->getComposerInformation($identifier);
                        $data['version_normalized'] = $this->versionParser->normalize($data['version']);
                        // Strip -dev that could have been left over accidentally in a tag
                        $data['version'] = preg_replace('{[.-]?dev$}i', '', $data['version']);
                        $this->updateInformation($output, $doctrine, $package, $repository, $identifier, $data);
                        $doctrine->getEntityManager()->flush();
                    }
                }

                foreach ($repository->getBranches() as $branch => $identifier) {
                    if ($repository->hasComposerFile($identifier) && $this->validateBranch($branch)) {
                        $data = $repository->getComposerInformation($identifier);
                        $data['version_normalized'] = $this->versionParser->normalize($data['version']);

                        // Skip branches that contain a version that's been tagged already
                        foreach ($package->getVersions() as $existingVersion) {
                            if ($data['version_normalized'] === $existingVersion->getNormalizedVersion() && !$existingVersion->getDevelopment()) {
                                continue;
                            }
                        }

                        // Force branches to use -dev releases
                        if (!preg_match('{[.-]?dev$}i', $data['version'])) {
                            $data['version'] .= '-dev';
                        }

                        $this->updateInformation($output, $doctrine, $package, $repository, $identifier, $data);
                        $doctrine->getEntityManager()->flush();
                    }
                }

                $package->setUpdatedAt(new \DateTime);
                $package->setCrawledAt(new \DateTime);
                $doctrine->getEntityManager()->flush();
            } catch (\Exception $e) {
                $output->writeln('<error>Exception: '.$e->getMessage().', skipping package '.$package->getName().'.</error>');
                continue;
            }
        }
    }

    private function validateBranch($branch)
    {
        if (in_array($branch, array('master', 'trunk'))) {
            return true;
        }

        return (Boolean) preg_match('#^v?(\d+)(\.(?:\d+|[x*]))?(\.(?:\d+|[x*]))?(\.[x*])?$#i', $branch, $matches);
    }

    private function validateTag($version)
    {
        try {
            $this->versionParser->normalize($version);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function updateInformation(OutputInterface $output, RegistryInterface $doctrine, $package, RepositoryInterface $repository, $identifier, array $data)
    {
        $em = $doctrine->getEntityManager();
        $version = new Version();

        $version->setName($package->getName());
        $version->setVersion($data['version']);
        $version->setNormalizedVersion($data['version_normalized']);
        $version->setDevelopment(substr($data['version'], -4) === '-dev');

        // check if we have that version yet
        foreach ($package->getVersions() as $existingVersion) {
            if ($existingVersion->equals($version)) {
                if ($existingVersion->getDevelopment()) {
                    $version = $existingVersion;
                    break;
                }
                return;
            }
        }

        $em->persist($version);

        $version->setDescription($data['description']);
        $version->setHomepage($data['homepage']);
        $version->setLicense(is_array($data['license']) ? $data['license'] : array($data['license']));

        $version->setPackage($package);
        $version->setUpdatedAt(new \DateTime);
        $version->setReleasedAt(new \DateTime($data['time']));
        $version->setSource($repository->getSource($identifier));
        $version->setDist($repository->getDist($identifier));

        if (isset($data['type'])) {
            $version->setType($data['type']);
            if ($data['type'] && $data['type'] !== $package->getType()) {
                $package->setType($data['type']);
            }
        }

        if (isset($data['target-dir'])) {
            $version->setTargetDir($data['target-dir']);
        }

        if (isset($data['extra']) && is_array($data['extra'])) {
            $version->setExtra($data['extra']);
        }

        $version->getTags()->clear();
        if (isset($data['keywords'])) {
            foreach ($data['keywords'] as $keyword) {
                $version->addTag(Tag::getByName($em, $keyword, true));
            }
        }

        $version->getAuthors()->clear();
        if (isset($data['authors'])) {
            foreach ($data['authors'] as $authorData) {
                $author = null;
                // skip authors with no information
                if (!isset($authorData['email']) && !isset($authorData['name'])) {
                    continue;
                }

                if (isset($authorData['email'])) {
                    $author = $doctrine->getRepository('PackagistWebBundle:Author')->findOneByEmail($authorData['email']);
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
            foreach ($version->{'get'.$linkType}() as $link) {
                // clear links that have changed/disappeared (for updates)
                if (!isset($data[$linkType][$link->getPackageName()]) || $data[$linkType][$link->getPackageName()] !== $link->getPackageVersion()) {
                    $version->{'get'.$linkType}()->removeElement($link);
                    $em->remove($link);
                } else {
                    // clear those that are already set
                    unset($data[$linkType][$link->getPackageName()]);
                }
            }

            if (isset($data[$linkType])) {
                foreach ($data[$linkType] as $linkPackageName => $linkPackageVersion) {
                    $class = 'Packagist\WebBundle\Entity\\'.$linkEntity;
                    $link = new $class;
                    $link->setPackageName($linkPackageName);
                    $link->setPackageVersion($linkPackageVersion);
                    $version->{'add'.$linkType.'Link'}($link);
                    $link->setVersion($version);
                    $em->persist($link);
                }
            }
        }
    }
}
