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

use App\Entity\SupportRequestMessage;

final readonly class SecondFactorResetResult
{
    public function __construct(
        public SecondFactorResetOutcome $outcome,
        private ?SupportRequestMessage $message = null,
    ) {
        if (($outcome === SecondFactorResetOutcome::Reset) !== ($message !== null)) {
            throw new \InvalidArgumentException('Only a completed reset has a reply.');
        }
    }

    public function reply(): SupportRequestMessage
    {
        return $this->message ?? throw new \LogicException('This reset outcome has no reply.');
    }
}
