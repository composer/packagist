<?php

namespace App\Validator;

use App\Entity\Package;
use App\Model\DownloadManager;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class CopyrightValidator extends ConstraintValidator
{
    public function __construct(
        private MailerInterface $mailer,
        private RequestStack $requestStack,
        private string $mailFromEmail,
        private UrlGeneratorInterface $urlGenerator,
    ) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof Copyright) {
            throw new UnexpectedTypeException($constraint, Copyright::class);
        }

        // custom constraints should ignore null and empty values to allow
        // other constraints (NotBlank, NotNull, etc.) to take care of that
        if (null === $value || '' === $value) {
            return;
        }

        if (!$value instanceof Package) {
            throw new UnexpectedValueException($value, Package::class);
        }

        $copyrightWatches = [
            'flarum' => 'legal@flarum.org',
            'symfony' => 'fabien@symfony.com',
        ];

        $req = $this->requestStack->getMainRequest();
        if (!$req || $req->attributes->get('_route') === 'submit.fetch_info') {
            return;
        }

        foreach ($copyrightWatches as $vendor => $email) {
            if ($value->getVendor() === $vendor || !str_contains($value->getVendor(), $vendor)) {
                continue;
            }

            $message = (new Email())
                ->subject('Packagist.org package submission notification: '.$value->getName().' contains '.$vendor.' in its vendor name')
                ->from(new Address($this->mailFromEmail))
                ->to($email)
                ->text('Check out '.$this->urlGenerator->generate('view_package', ['name' => $value->getName()], UrlGeneratorInterface::ABSOLUTE_URL).' for copyright infringement.')
            ;
            $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');
            $this->mailer->send($message);
        }
    }
}
