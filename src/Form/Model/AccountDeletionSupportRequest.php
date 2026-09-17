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

use App\Support\PackageDisposition;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
    public function validateTransferTarget(ExecutionContextInterface $context): void
    {
        if ($this->packageDisposition === PackageDisposition::Transfer && ($this->transferTo === null || trim($this->transferTo) === '')) {
            $context->buildViolation('Tell us which account the packages should go to.')
                ->atPath('transferTo')
                ->addViolation();
        }
    }
}
