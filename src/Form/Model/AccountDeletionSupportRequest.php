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

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

/** What should happen to the packages that stop the account being deleted self-service. */
enum PackageDisposition: string
{
    case Transfer = 'transfer';
    case Abandon = 'abandon';
    case Delete = 'delete';
    case Undecided = 'undecided';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => 'Transfer them to another account',
            self::Abandon => 'Mark them abandoned and leave them up',
            self::Delete => 'Delete them along with my account',
            self::Undecided => 'I am not sure, please advise',
        };
    }
}

class AccountDeletionSupportRequest
{
    #[Assert\NotNull]
    public ?PackageDisposition $packageDisposition = null;

    /** Username to hand the packages to. Only meaningful with PackageDisposition::Transfer. */
    #[Assert\Length(max: 191)]
    public ?string $transferTo = null;

    #[Assert\Length(max: 4000)]
    public ?string $description = null;

    #[Assert\IsTrue(message: 'Please confirm you understand that deleting the account cannot be undone.')]
    public bool $acknowledged = false;

    #[Assert\Callback]
    public function validateTransferTarget(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        if ($this->packageDisposition === PackageDisposition::Transfer && ($this->transferTo === null || trim($this->transferTo) === '')) {
            $context->buildViolation('Tell us which account the packages should go to.')
                ->atPath('transferTo')
                ->addViolation();
        }
    }
}
