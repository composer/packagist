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
        $em = $this->getContainer()->get('doctrine')->getEntityManager();
        $logger = $this->getContainer()->get('logger');
        $provider = $this->getContainer()->get('repository_provider');

        $qb = $em->createQueryBuilder();
        $qb->select('p, v')
            ->from('Packagist\WebBundle\Entity\Package', 'p')
            ->leftJoin('p.versions', 'v')
            ->where('p.crawledAt IS NULL OR p.crawledAt < ?0')
            ->setParameters(array(date('Y-m-d H:i:s', time() - 3600)));

        foreach ($qb->getQuery()->getResult() as $package) {

            // Process GitHub via API
            if ($repo = $provider->getRepository($package->getRepository())) {

                $output->writeln('Importing '.$repo->getUrl());

                try {
                    $files = $repo->getAllComposerFiles();
                }
                catch (Exception $ex) {
                    $output->writeln('Err: Could not fetch data from: '.$repo->getUrl().', skipping.');
                    continue;
                }

                foreach ($files as $uniqid => $data) {

                    // TODO parse $data['version'] w/ composer version parser, if no match, ignore the tag

                    // check if we have that version yet
                    foreach ($package->getVersions() as $version) {
                        if ($version->getVersion() === $data['version']) {
                            continue 2;
                        }
                    }

                    if ($data['name'] !== $package->getName()) {
                        $output->writeln('Err: Package name seems to have changed for '.$repo->getUrl().'@'.$uniqid.', skipping');
                        continue;
                    }

                    $version = new Version();
                    $em->persist($version);

                    foreach (array('name', 'description', 'homepage', 'license', 'version') as $field) {
                        if (isset($data[$field])) {
                            $version->{'set'.$field}($data[$field]);
                        }
                    }

                    //TODO: This should be done in getAllComoserFiles()
                    // fetch date from the commit if not specified
                    if (!isset($data['time'])) {
                        $data['time'] = $repo->getTime($uniqid);
                    }

                    $version->setPackage($package);
                    $version->setUpdatedAt(new \DateTime);
                    $version->setReleasedAt(new \DateTime($data['time']));
                    $version->setSource($repo->getSource());
                    $version->setDist($repo->getDist($uniqid));

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
                                $qb = $em->createQueryBuilder();
                                $qb->select('a')
                                    ->from('Packagist\WebBundle\Entity\Author', 'a')
                                    ->where('a.email = ?0')
                                    ->setParameters(array($authorData['email']))
                                    ->setMaxResults(1);
                                $author = $qb->getQuery()->getOneOrNullResult();
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

                // TODO parse composer.json on every branch matching a "$num.x.x" version scheme, + the master one, for all "x.y.z-dev" versions, usable through "latest-dev"
            } else {
                // TODO support other repos
                $output->writeln('Err: unsupported repository: '.$package->getRepository());
                continue;
            }
            $package->setUpdatedAt(new \DateTime);
            $package->setCrawledAt(new \DateTime);
            $em->flush();
        }
    }
}
