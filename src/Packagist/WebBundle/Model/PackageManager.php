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

namespace Packagist\WebBundle\Model;

use Doctrine\ORM\EntityManager;
use Packagist\WebBundle\Entity\Package;
use Symfony\Component\HttpKernel\Log\LoggerInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackageManager
{
    protected $em;
    protected $mailer;
    protected $twig;
    protected $logger;
    protected $options;

    public function __construct(EntityManager $em, \Swift_Mailer $mailer, \Twig_Environment $twig, LoggerInterface $logger, array $options)
    {
        $this->em = $em;
        $this->mailer = $mailer;
        $this->twig = $twig;
        $this->logger = $logger;
        $this->options = $options;
    }

    public function notifyUpdateFailure(Package $package, \Exception $e, $details = null)
    {
        if (!$package->isUpdateFailureNotified()) {
            $recipients = array();
            foreach ($package->getMaintainers() as $maintainer) {
                if ($maintainer->isNotifiableForFailures()) {
                    $recipients[$maintainer->getEmail()] = $maintainer->getUsername();
                }
            }

            if ($recipients) {
                $body = $this->twig->render('PackagistWebBundle:Email:update_failed.txt.twig', array(
                    'package' => $package,
                    'exception' => get_class($e),
                    'exceptionMessage' => $e->getMessage(),
                    'details' => $details,
                ));

                $message = \Swift_Message::newInstance()
                    ->setSubject($package->getName().' failed to update, invalid composer.json data')
                    ->setFrom($this->options['from'], $this->options['fromName'])
                    ->setTo($recipients)
                    ->setBody($body)
                ;

                try {
                    $this->mailer->send($message);
                } catch (\Swift_TransportException $e) {
                    $this->logger->error('['.get_class($e).'] '.$e->getMessage());

                    return false;
                }
            }

            $package->setUpdateFailureNotified(true);
            $this->em->flush();
        }

        return true;
    }

    public function notifyNewMaintainer($user, $package)
    {
        $body = $this->twig->render('PackagistWebBundle:Email:maintainer_added.txt.twig', array(
            'package_name' => $package->getName()
        ));

        $message = \Swift_Message::newInstance()
            ->setSubject('You have been added to ' . $package->getName() . ' as a maintainer')
            ->setFrom($this->options['from'], $this->options['fromName'])
            ->setTo($user->getEmail())
            ->setBody($body)
        ;

        try {
            $this->mailer->send($message);
        } catch (\Swift_TransportException $e) {
            $this->logger->error('['.get_class($e).'] '.$e->getMessage());

            return false;
        }

        return true;
    }
}
