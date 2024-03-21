<?php declare(strict_types=1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Security;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * @author Pierre Ambroise <pierre27.ambroise@gmail.com>
 */
class UserNotifier
{
    public function __construct(
        private string $mailFromEmail,
        private string $mailFromName,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, mixed> $templateVars
     */
    public function notifyChange(string $email, string $reason = '', string $template = 'email/alert_change.txt.twig', string $subject = 'A change has been made to your Packagist.org account', ...$templateVars): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFromEmail, $this->mailFromName))
            ->to($email)
            ->subject($subject)
            ->textTemplate($template)
            ->context([
                'reason' => $reason,
                ...$templateVars
            ]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('['.get_class($e).'] '.$e->getMessage());
        }
    }
}
