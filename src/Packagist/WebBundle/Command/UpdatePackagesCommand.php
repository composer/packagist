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
use Packagist\WebBundle\Entity\Requirement;
use Packagist\WebBundle\Repository\Repository\RepositoryInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UpdatePackagesCommand extends ContainerAwareCommand
{
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
        $doctrine = $this->getContainer()->get('doctrine');

        $logger = $this->getContainer()->get('logger');
        $provider = $this->getContainer()->get('packagist.repository_provider');

        $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getStalePackages();

        foreach ($packages as $package) {
            $repository = $provider->getRepository($package->getRepository());

            if (!$repository) {
                $output->writeln('<error>Unsupported repository: '.$package->getRepository().'</error>');
                continue;
            }

            $output->writeln('Importing '.$repository->getUrl());

            try {
                foreach ($repository->getTags() as $tag => $identifier) {
                    if ($repository->hasComposerFile($identifier) && $this->parseVersion($tag)) {
                        $data = $repository->getComposerInformation($identifier);
                        // Strip -dev that could have been left over accidentally in a tag
                        $data['version'] = preg_replace('{-?dev$}i', '', $data['version']);
                        $this->updateInformation($output, $doctrine, $package, $repository, $identifier, $data);
                        $doctrine->getEntityManager()->flush();
                    }
                }

                foreach ($repository->getBranches() as $branch => $identifier) {
                    if ($repository->hasComposerFile($identifier) && ($parsed = $this->parseBranch($branch))) {
                        $data = $repository->getComposerInformation($identifier);
                        $parsedVersion = $this->parseVersion($data['version']);

                        // Skip branches that contain a version that's been tagged already
                        foreach ($package->getVersions() as $existingVersion) {
                            if ($parsedVersion['version'] === $existingVersion->getVersion() && !$existingVersion->getDevelopment()) {
                                continue;
                            }
                        }

                        // Force branches to use -dev type releases
                        $data['version'] = $parsedVersion['version'].'-'.$parsedVersion['type'].'-dev';

                        $this->updateInformation($output, $doctrine, $package, $repository, $identifier, $data);
                        $doctrine->getEntityManager()->flush();
                    }
                }

                $package->setUpdatedAt(new \DateTime);
                $package->setCrawledAt(new \DateTime);
                $doctrine->getEntityManager()->flush();
            } catch (\Exception $e) {
                $output->writeln('<error>Exception: '.$e->getMessage().', skipping package.</error>');
                continue;
            }
        }
    }

    private function parseBranch($branch)
    {
        if (in_array($branch, array('master', 'trunk'))) {
            return 'master';
        }

        if (!preg_match('#^v?(\d+)(\.(?:\d+|[x*]))?(\.[x*])?$#i', $branch, $matches)) {
            return false;
        }

        return $matches[1]
            .(!empty($matches[2]) ? strtr($matches[2], '*', 'x') : '.x')
            .(!empty($matches[3]) ? strtr($matches[3], '*', 'x') : '.x');
    }

    private function parseVersion($version)
    {
        if (!preg_match('#^v?(\d+)(\.\d+)?(\.\d+)?-?((?:beta|RC|alpha)\d*)?-?(dev)?$#i', $version, $matches)) {
            return false;
        }

        return array(
            'version' => $matches[1]
                .(!empty($matches[2]) ? $matches[2] : '.0')
                .(!empty($matches[3]) ? $matches[3] : '.0'),
            'type' => !empty($matches[4]) ? strtolower($matches[4]) : '',
            'dev' => !empty($matches[5]),
        );
    }

    private function updateInformation(OutputInterface $output, RegistryInterface $doctrine, $package, RepositoryInterface $repository, $identifier, array $data)
    {
        if ($data['name'] !== $package->getName()) {
            $output->writeln('<error>Package name seems to have changed for '.$repository->getUrl().'@'.$identifier.', skipping.</error>');
            return;
        }

        $em = $doctrine->getEntityManager();
        $version = new Version();

        $parsedVersion = $this->parseVersion($data['version']);
        $version->setName($data['name']);
        $version->setVersion($parsedVersion['version']);
        $version->setVersionType($parsedVersion['type']);
        $version->setDevelopment($parsedVersion['dev']);

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
        $version->setLicense($data['license']);

        $version->setPackage($package);
        $version->setUpdatedAt(new \DateTime);
        $version->setReleasedAt(new \DateTime($data['time']));
        $version->setSource(array('type' => $repository->getType(), 'url' => $repository->getUrl()));
        $version->setDist($repository->getDist($identifier));

        if (isset($data['type'])) {
            $version->setType($data['type']);
            if ($data['type'] && $data['type'] !== $package->getType()) {
                $package->setType($data['type']);
            }
        }

        if (isset($data['extra']) && is_array($data['extra'])) {
            $version->setExtra($data['extra']);
        }

        $version->getTags()->clear();
        if (isset($data['keywords'])) {
            foreach ($data['keywords'] as $keyword) {
                $version->addTags(Tag::getByName($em, $keyword, true));
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
                    $version->addAuthors($author);
                }
                if (!$author->getVersions()->contains($version)) {
                    $author->addVersions($version);
                }
            }
        }

        foreach ($version->getRequirements() as $req) {
            // clear requirements that have changed/disappeared (for updates)
            if (!isset($data['require'][$req->getPackageName()]) || $data['require'][$req->getPackageName()] !== $req->getPackageVersion()) {
                $version->getRequirements()->removeElement($req);
                $em->remove($req);
            } else {
                // clear those that are already set
                unset($data['require'][$req->getPackageName()]);
            }
        }

        if (isset($data['require'])) {
            foreach ($data['require'] as $requireName => $requireVersion) {
                $requirement = new Requirement();
                $requirement->setPackageName($requireName);
                $requirement->setPackageVersion($requireVersion);
                $version->addRequirements($requirement);
                $requirement->setVersion($version);
                $em->persist($requirement);
            }
        }
    }
}
