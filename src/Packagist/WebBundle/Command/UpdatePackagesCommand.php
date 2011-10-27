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
            ->setName('packagist:update')
            ->setDefinition(array(
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a re-crawl of all packages'),
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
        $force = $input->getOption('force');
        $doctrine = $this->getContainer()->get('doctrine');

        $logger = $this->getContainer()->get('logger');
        $provider = $this->getContainer()->get('packagist.repository_provider');

        $this->versionParser = new VersionParser;

        if ($force) {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->findAll();
        } else {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getStalePackages();
        }

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
                    if ($repository->hasComposerFile($identifier) && $parsedTag = $this->validateTag($tag)) {
                        $data = $repository->getComposerInformation($identifier);

                        // manually versioned package
                        if (isset($data['version'])) {
                            $data['version_normalized'] = $this->versionParser->normalize($data['version']);
                            if ($data['version_normalized'] !== $parsedTag) {
                                // broken package, version doesn't match tag
                                continue;
                            }
                        } else {
                            // auto-versionned package, read value from tag
                            $data['version'] = preg_replace('{[.-]?dev$}i', '', $tag);
                            $data['version_normalized'] = preg_replace('{[.-]?dev$}i', '', $parsedTag);
                        }
                        $this->updateInformation($output, $doctrine, $package, $repository, $identifier, $data);
                        $doctrine->getEntityManager()->flush();
                    }
                }

                foreach ($repository->getBranches() as $branch => $identifier) {
                    if ($repository->hasComposerFile($identifier) && $parsedBranch = $this->validateBranch($branch)) {
                        $data = $repository->getComposerInformation($identifier);

                        // manually versioned package
                        if (isset($data['version'])) {
                            $data['version_normalized'] = $this->versionParser->normalize($data['version']);
                        } else {
                            // auto-versionned package, read value from branch name
                            $data['version'] = $branch;
                            $data['version_normalized'] = $parsedBranch;
                        }

                        // make sure branch packages have a -dev flag
                        $data['version'] = preg_replace('{[.-]?dev$}i', '', $data['version']) . '-dev';
                        $data['version_normalized'] = preg_replace('{[.-]?dev$}i', '', $data['version_normalized']) . '-dev';

                        // Skip branches that contain a version that has been tagged already
                        foreach ($package->getVersions() as $existingVersion) {
                            if ($data['version_normalized'] === $existingVersion->getNormalizedVersion() && !$existingVersion->getDevelopment()) {
                                continue;
                            }
                        }

                        $this->updateInformation($output, $doctrine, $package, $repository, $identifier, $data);
                        $doctrine->getEntityManager()->flush();
                    }
                }

                // TODO -dev versions that were not updated should be deleted

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
        try {
            return $this->versionParser->normalizeBranch($branch);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function validateTag($version)
    {
        try {
            return $this->versionParser->normalize($version);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function updateInformation(OutputInterface $output, RegistryInterface $doctrine, $package, RepositoryInterface $repository, $identifier, array $data)
    {
        $em = $doctrine->getEntityManager();
        $version = new Version();

        $version->setName($package->getName());
        $version->setNormalizedVersion(preg_replace('{-dev$}i', '', $data['version_normalized']));

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

        $version->setVersion($data['version']);
        $version->setDevelopment(substr($data['version_normalized'], -4) === '-dev');

        $em->persist($version);

        $version->setDescription($data['description']);
        $package->setDescription($data['description']);
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

        if (isset($data['autoload'])) {
            $version->setAutoload($data['autoload']);
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

        if (!$package->getVersions()->contains($version)) {
            $package->addVersions($version);
        }
    }
}
