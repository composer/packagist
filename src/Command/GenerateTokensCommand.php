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

namespace App\Command;

use Doctrine\Persistence\ManagerRegistry;
use FOS\UserBundle\Util\TokenGeneratorInterface;
use App\Entity\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GenerateTokensCommand extends Command
{
    private TokenGeneratorInterface $tokenGenerator;
    private ManagerRegistry $doctrine;

    public function __construct(TokenGeneratorInterface $tokenGenerator, ManagerRegistry $doctrine)
    {
        $this->tokenGenerator = $tokenGenerator;
        $this->doctrine = $doctrine;
        parent::__construct();
    }

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
        $userRepo = $this->doctrine->getRepository(User::class);

        $users = $userRepo->findUsersMissingApiToken();
        foreach ($users as $user) {
            $apiToken = substr($this->tokenGenerator->generateToken(), 0, 20);
            $user->setApiToken($apiToken);
        }
        $this->doctrine->getManager()->flush();
    }
}
