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
    /** @var Collection<int, User> */
    #[TransferPackageValidMaintainersList]
    private Collection $maintainers;

    public function __construct()
    {
        $this->maintainers = new ArrayCollection();
    }

    /**
     * @return Collection<int, User>
     */
    public function getMaintainers(): Collection
    {
        return $this->maintainers;
    }

    /**
     * @param Collection<int, User> $maintainers
     */
    public function setMaintainers(Collection $maintainers): void
    {
        $this->maintainers = $maintainers;
    }
}
