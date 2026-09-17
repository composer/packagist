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

namespace App\Support;

use App\Entity\SupportRequest;
use App\Entity\SupportRequestMessage;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Every mail this sends is plain text, and .txt.twig templates are never escaped by Twig
 * (FileExtensionEscapingStrategy returns false for txt, so an {% autoescape %} flag would be a
 * no-op). So none of these mails interpolate requester or admin free text except the reply body,
 * which the admin wrote and reviewed, and which is fenced off in the template.
 */
class SupportNotifier
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly string $mailFromEmail,
        private readonly string $mailFromName,
    ) {
    }

    /**
     * Tells the team a request is waiting. Carries no user-supplied text at all: that keeps the
     * shared mailbox from becoming a spam target, and makes the admin open the authenticated queue
     * page — where the risk panel is — rather than judging from an email.
     */
    public function notifyAdmins(SupportRequest $request): void
    {
        $url = $this->urlGenerator->generate('admin_support_request', ['publicId' => $request->publicId], UrlGeneratorInterface::ABSOLUTE_URL);

        $body = <<<TXT
            A new support request is waiting.

            Type:      {$request->type->label()}
            Requester: {$request->user->getUsername()}
            Reference: {$request->publicId}

            Review it here: {$url}
            TXT;

        $message = new Email()
            ->subject('[Support] '.$request->type->label().' request from '.$request->user->getUsername().' ('.$request->publicId.')')
            ->from(new Address($this->mailFromEmail, $this->mailFromName))
            ->to($this->mailFromEmail)
            ->text($body)
        ;
        $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');

        $this->send($message);
    }

    /**
     * Escalates an owner saying "this was not me" about a request we had already actioned: the reset
     * has happened and the account should be treated as compromised.
     */
    public function notifyAdminsOfDisputedRequest(SupportRequest $request): void
    {
        $url = $this->urlGenerator->generate('admin_support_request', ['publicId' => $request->publicId], UrlGeneratorInterface::ABSOLUTE_URL);

        $body = <<<TXT
            The owner of {$request->user->getUsername()} used the cancellation link AFTER support
            request {$request->publicId} was actioned, so the two-factor reset was not theirs.

            Their sessions have been invalidated. Treat the account as compromised.

            {$url}
            TXT;

        $message = new Email()
            ->subject('[Support] DISPUTED two-factor reset on '.$request->user->getUsername().' ('.$request->publicId.')')
            ->from(new Address($this->mailFromEmail, $this->mailFromName))
            ->to($this->mailFromEmail)
            ->priority(Email::PRIORITY_HIGH)
            ->text($body)
        ;
        $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');

        $this->send($message);
    }

    /**
     * Warns the account owner that someone who knows their password asked us to reset two-factor
     * authentication, and gives them a link to veto it without waiting for an admin.
     *
     * Deliberately carries none of the requester's text: the requester is not yet known to be the
     * owner, and this mail goes out over the Packagist address.
     */
    public function notifyTwoFactorRequestFiled(SupportRequest $request, string $cancelToken): void
    {
        $email = new TemplatedEmail()
            ->from(new Address($this->mailFromEmail, $this->mailFromName))
            ->to($request->user->getEmail())
            ->replyTo($this->mailFromEmail)
            ->subject('Two-factor reset requested on your Packagist.org account')
            ->textTemplate('email/support_two_factor_requested.txt.twig')
            ->context([
                'publicId' => $request->publicId,
                'requestedAt' => $request->createdAt,
                'ip' => $request->ip,
                'cancelUrl' => $this->urlGenerator->generate(
                    'support_cancel_2fa_request',
                    ['publicId' => $request->publicId, 'token' => $cancelToken],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                ),
                'changePasswordUrl' => $this->urlGenerator->generate('change_password', [], UrlGeneratorInterface::ABSOLUTE_URL),
                'contactEmail' => $this->mailFromEmail,
            ])
        ;
        $email->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');

        $this->send($email);
    }

    /** Sends an admin's reply to the requester. Replies come back to the shared mailbox. */
    public function notifyUserOfReply(SupportRequest $request, SupportRequestMessage $reply): void
    {
        $email = new TemplatedEmail()
            ->from(new Address($this->mailFromEmail, $this->mailFromName))
            ->to($request->user->getEmail())
            ->replyTo($this->mailFromEmail)
            ->subject('Re: your Packagist.org support request ('.$request->publicId.')')
            ->textTemplate('email/support_reply.txt.twig')
            ->context([
                'publicId' => $request->publicId,
                'typeLabel' => $request->type->label(),
                'contents' => $reply->contents,
                'contactEmail' => $this->mailFromEmail,
            ])
        ;
        $email->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');

        $this->send($email);
    }

    /** A transport failure must never break the request or the admin action that triggered it. */
    private function send(Email $message): void
    {
        try {
            $this->mailer->send($message);
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('['.$e::class.'] '.$e->getMessage());
        }
    }
}
