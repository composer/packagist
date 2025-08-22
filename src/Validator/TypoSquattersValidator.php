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

namespace App\Validator;

use App\Entity\Package;
use App\Model\DownloadManager;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class TypoSquattersValidator extends ConstraintValidator
{
    use DoctrineTrait;

    public function __construct(
        private ManagerRegistry $doctrine,
        private DownloadManager $downloadManager,
        private MailerInterface $mailer,
        private RequestStack $requestStack,
        private string $mailFromEmail,
        private UrlGeneratorInterface $urlGenerator,
        private Security $security,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof TypoSquatters) {
            throw new UnexpectedTypeException($constraint, TypoSquatters::class);
        }

        // custom constraints should ignore null and empty values to allow
        // other constraints (NotBlank, NotNull, etc.) to take care of that
        if (null === $value || '' === $value) {
            return;
        }

        if (!$value instanceof Package) {
            throw new UnexpectedValueException($value, Package::class);
        }

        /** @var array<array{id: int, name: string}> $existingPackages */
        $existingPackages = $this->getEM()
            ->getConnection()
            ->fetchAllAssociative(
                'SELECT id, name FROM package WHERE name LIKE :query',
                ['query' => '%/'.$value->getPackageName()]
            );

        foreach ($existingPackages as $existingPackage) {
            // duplicate submission, ignore it as it is caught elsewhere
            if ($existingPackage['name'] === $value->getName()) {
                continue;
            }

            $existingVendor = explode('/', $existingPackage['name'])[0];
            if (levenshtein($existingVendor, $value->getVendor()) <= 1) {
                $existingPkg = $this->getEM()->getRepository(Package::class)->find($existingPackage['id']);
                if ($existingPkg !== null) {
                    foreach ($existingPkg->getMaintainers() as $maintainer) {
                        // current user is maintainer of existing conflicting pkg, so probably a false alarm
                        if ($maintainer === $this->security->getUser()) {
                            return;
                        }
                    }
                }

                if ($this->downloadManager->getTotalDownloads($existingPackage['id']) >= 1_000_000) {
                    $this->context->buildViolation($constraint->message)
                        ->setParameter('{{ name }}', $value->getName())
                        ->setParameter('{{ existing }}', $existingPackage['name'])
                        ->atPath('repository')
                        ->addViolation();

                    return;
                }

                $req = $this->requestStack->getMainRequest();
                if ($req && $req->attributes->get('_route') !== 'submit.fetch_info') {
                    $message = new Email()
                        ->subject($value->getName().' is suspiciously close to '.$existingPackage['name'])
                        ->from(new Address($this->mailFromEmail))
                        ->to($this->mailFromEmail)
                        ->text('Check out '.$this->urlGenerator->generate('view_package', ['name' => $value->getName()], UrlGeneratorInterface::ABSOLUTE_URL).' is not hijacking '.$this->urlGenerator->generate('view_package', ['name' => $existingPackage['name']], UrlGeneratorInterface::ABSOLUTE_URL))
                    ;
                    $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');
                    $this->mailer->send($message);
                }
            }
        }
    }
}
