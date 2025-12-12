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

use App\Entity\User;
use App\Validator\Constraints\TransferPackageValidMaintainersList;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;

class TransferPackageRequest
{
    public function __construct(
        /** @var Collection<int, User> */
        #[TransferPackageValidMaintainersList]
        public Collection $maintainers = new ArrayCollection(),
    ) {
    }
}
