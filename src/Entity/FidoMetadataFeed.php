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

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'fido_metadata_feed')]
class FidoMetadataFeed
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column] public readonly int $sequenceNumber,
        #[ORM\Column(type: 'datetime_immutable')] public readonly \DateTimeImmutable $nextUpdate,
        #[ORM\Column(type: 'datetime_immutable')] public readonly \DateTimeImmutable $importedAt,
    ) {
    }
}
