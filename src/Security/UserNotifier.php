<?php

namespace App\Security;

use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * @author Pierre Ambroise <pierre27.ambroise@gmail.com>
 */
class UserNotifier
{
    private MailerInterface $mailer;
    private string $mailFromEmail;
    private string $mailFromName;

    public function __construct(string $mailFromEmail, string $mailFromName, MailerInterface $mailer)
    {
        $this->mailer = $mailer;
        $this->mailFromEmail = $mailFromEmail;
        $this->mailFromName = $mailFromName;
    }

    public function notifyChange(string $email, string $reason): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->mailFromEmail, $this->mailFromName))
            ->to($email)
            ->subject('A change has been made to your account')
            ->textTemplate('email/alert_change.txt.twig')
            ->context([
                'reason' => $reason,
            ]);

        $this->mailer->send($email);
    }
}
