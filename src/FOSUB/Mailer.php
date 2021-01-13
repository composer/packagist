<?php

namespace App\FOSUB;

use FOS\UserBundle\Mailer\Mailer as FOSUBMailer;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

class Mailer extends FOSUBMailer
{
    public function __construct(MailerInterface $mailer, UrlGeneratorInterface $router, Environment $templating, array $parameters)
    {
        $this->mailer = $mailer;
        $this->router = $router;
        $this->templating = $templating;
        $this->parameters = $parameters;
    }

    /**
     * @param string $renderedTemplate
     * @param array  $fromEmail
     * @param string $toEmail
     */
    protected function sendEmailMessage($renderedTemplate, $fromEmail, $toEmail)
    {
        if (!is_array($fromEmail) || is_array($toEmail)) {
            throw new \UnexpectedValueException('Expected array $fromEmail and string $toEmail');
        }
        // Render the email, use the first line as the subject, and the rest as the body
        $renderedLines = explode("\n", trim($renderedTemplate));
        $subject = array_shift($renderedLines);
        $body = implode("\n", $renderedLines);

        $message = (new Email())
            ->subject($subject)
            ->from(new Address(key($fromEmail), current($fromEmail)))
            ->to($toEmail)
            ->text($body);

        $this->mailer->send($message);
    }
}