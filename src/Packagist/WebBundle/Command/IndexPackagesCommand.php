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

/**
 * @author Igor Wiedler <igor@wiedler.ch>
 */
class IndexPackagesCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:index')
            ->setDefinition(array(
                new InputOption('force', null, InputOption::VALUE_NONE, 'Force a re-indexing of all packages'),
                new InputOption('package', null, InputOption::VALUE_NONE, 'Package name to index (implicitly enables --force)'),
            ))
            ->setDescription('Indexes packages')
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
        $solarium = $this->getContainer()->get('solarium.client');

        if ($input->getOption('package')) {
            $packages = array($doctrine->getRepository('PackagistWebBundle:Package')->findOneByName($input->getOption('package')));
        } elseif ($input->getOption('force')) {
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->findAll();
        } else {
            // TODO: query for unindexed packages
            $packages = $doctrine->getRepository('PackagistWebBundle:Package')->getStalePackages();
        }

        foreach ($packages as $package) {
            if ($verbose) {
                $output->writeln('Indexing '.$package->getName());
            }

            try {
                $update = $solarium->createUpdate();

                $document = $update->createDocument();
                $document->id = $package->getId();
                $document->name = $package->getName();
                $document->description = $package->getDescription();

                $update->addDocument($document);
                $update->addCommit();

                $result = $solarium->update($update);

                var_dump($result->getStatus());
            } catch (\Exception $e) {
                $output->writeln('<error>Exception: '.$e->getMessage().', skipping package '.$package->getName().'.</error>');
            }
        }
    }
}
