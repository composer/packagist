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

namespace Packagist\WebBundle\Security;

use Packagist\WebBundle\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Twig\Environment;

/**
 * @author Colin O'Dell <colinodell@gmail.com>
 */
class TwoFactorAuthManager
{
    protected $doctrine;
    protected $mailer;
    protected $twig;
    protected $logger;
    protected $options;

    public function __construct(RegistryInterface $doctrine, \Swift_Mailer $mailer, Environment $twig, LoggerInterface $logger, array $options)
    {
        $this->doctrine = $doctrine;
        $this->mailer = $mailer;
        $this->twig = $twig;
        $this->logger = $logger;
        $this->options = $options;
    }

    /**
     * Enable two-factor auth on the given user account and send confirmation email.
     *
     * @param User   $user
     * @param string $secret
     */
    public function enableTwoFactorAuth(User $user, string $secret)
    {
        $user->setTotpSecret($secret);
        $this->doctrine->getManager()->flush();

        $body = $this->twig->render('PackagistWebBundle:email:two_factor_enabled.txt.twig', array(
            'username' => $user->getUsername(),
        ));

        $message = (new \Swift_Message)
            ->setSubject('[Packagist] Two-factor authentication enabled')
            ->setFrom($this->options['from'], $this->options['fromName'])
            ->setTo($user->getEmail())
            ->setBody($body)
        ;

        try {
            $this->mailer->send($message);
        } catch (\Swift_TransportException $e) {
            $this->logger->error('['.get_class($e).'] '.$e->getMessage());
        }
    }

    /**
     * Disable two-factor auth on the given user account and send confirmation email.
     *
     * @param User   $user
     */
    public function disableTwoFactorAuth(User $user)
    {
        $user->setTotpSecret(null);
        $this->doctrine->getManager()->flush();

        $body = $this->twig->render('PackagistWebBundle:email:two_factor_disabled.txt.twig', array(
            'username' => $user->getUsername(),
        ));

        $message = (new \Swift_Message)
            ->setSubject('[Packagist] Two-factor authentication disabled')
            ->setFrom($this->options['from'], $this->options['fromName'])
            ->setTo($user->getEmail())
            ->setBody($body)
        ;

        try {
            $this->mailer->send($message);
        } catch (\Swift_TransportException $e) {
            $this->logger->error('['.get_class($e).'] '.$e->getMessage());
        }
    }
}