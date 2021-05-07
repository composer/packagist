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

use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\User;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class GenerateTokensCommand extends Command
{
    use DoctrineTrait;

    private ManagerRegistry $doctrine;

    public function __construct(ManagerRegistry $doctrine)
    {
        $this->doctrine = $doctrine;
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->setName('packagist:tokens:generate')
            ->setDescription('Generates all missing user tokens')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $userRepo = $this->getEM()->getRepository(User::class);

        $users = $userRepo->findUsersMissingApiToken();
        foreach ($users as $user) {
            $user->initializeApiToken();
        }
        $this->doctrine->getManager()->flush();

        return 0;
    }
}
