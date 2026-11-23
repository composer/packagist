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
#[ORM\Table(name: 'fido_metadata_entry', options: ['collation' => 'utf8mb4_unicode_ci'])]
class FidoMetadataEntry
{
    /**
     * @param array<string, mixed>       $statement
     * @param list<array<string, mixed>> $statusReports
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 36)] public readonly string $aaguid,
        #[ORM\Column(type: 'json')] public readonly array $statement,
        #[ORM\Column(type: 'json')] public readonly array $statusReports,
    ) {
    }
}
