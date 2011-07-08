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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Finder\Finder;
use Packagist\WebBundle\Entity\Version;
use Packagist\WebBundle\Entity\Tag;
use Packagist\WebBundle\Entity\Author;
use Packagist\WebBundle\Entity\Requirement;

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
                    // TODO parse tag name (or fetch composer file?) w/ composer version parser, if no match, ignore the tag
                    $this->fetchInformation($output, $doctrine, $package, $repository, $identifier);
                }

                foreach ($repository->getBranches() as $branch => $identifier) {
                    // TODO parse branch name, matching a "$num.x.x" version scheme, + the master one
                    // use for all "x.y.z-dev" versions, usable through "latest-dev"
                    $this->fetchInformation($output, $doctrine, $package, $repository, $identifier);
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

    protected function fetchInformation(OutputInterface $output, $doctrine, $package, $repository, $identifier)
    {
        $data = $repository->getComposerInformation($identifier);
        $em = $doctrine->getEntityManager();

        // check if we have that version yet
        foreach ($package->getVersions() as $version) {
            if ($version->getVersion() === $data['version']) {
                return;
            }
        }

        if ($data['name'] !== $package->getName()) {
            $output->writeln('<error>Package name seems to have changed for '.$repository->getUrl().'@'.$identifier.', skipping.</error>');
            return;
        }

        $version = new Version();
        $em->persist($version);

        foreach (array('name', 'description', 'homepage', 'license', 'version') as $field) {
            if (isset($data[$field])) {
                $version->{'set'.$field}($data[$field]);
            }
        }

        $version->setPackage($package);
        $version->setUpdatedAt(new \DateTime);
        $version->setReleasedAt(new \DateTime($data['time']));
        $version->setSource(array('type' => $repository->getType(), 'url' => $repository->getUrl()));
        $version->setDist($repository->getDist($identifier));

        if (isset($data['keywords'])) {
            foreach ($data['keywords'] as $keyword) {
                $version->addTags(Tag::getByName($em, $keyword, true));
            }
        }

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
                $version->addAuthors($author);
                $author->addVersions($version);
            }
        }

        if (isset($data['require'])) {
            foreach ($data['require'] as $requireName => $requireVersion) {
                $requirement = new Requirement();
                $em->persist($requirement);
                $requirement->setPackageName($requireName);
                $requirement->setPackageVersion($requireVersion);
                $version->addRequirements($requirement);
                $requirement->setVersion($version);
            }
        }
    }
}
