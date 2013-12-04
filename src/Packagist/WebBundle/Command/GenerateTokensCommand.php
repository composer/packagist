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
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GenerateTokensCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:tokens:generate')
            ->setDescription('Generates all missing user tokens')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $doctrine = $this->getContainer()->get('doctrine');
        $userRepo = $doctrine->getRepository('PackagistWebBundle:User');
        $tokenGenerator = $this->getContainer()->get('fos_user.util.token_generator');

        $users = $userRepo->findUsersMissingApiToken();
        foreach ($users as $user) {
            $apiToken = substr($tokenGenerator->generateToken(), 0, 20);
            $user->setApiToken($apiToken);
        }
        $doctrine->getManager()->flush();
    }
}
