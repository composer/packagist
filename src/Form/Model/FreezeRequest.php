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

use App\Entity\PackageFreezeReason;
use App\Entity\UserFreezeReason;
use Symfony\Component\Validator\Constraints as Assert;

class FreezeRequest
{
    #[Assert\NotNull]
    public ?UserFreezeReason $reason = null;

    public ?string $reasonText = null;

    public ?string $internalReason = null;

    public bool $freezePackages = true;

    public ?PackageFreezeReason $packageFreezeReason = PackageFreezeReason::Spam;

    public bool $purgePackages = false;
}
