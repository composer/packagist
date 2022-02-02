<?php

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

class SearchQuery
{
    /**
     * @Assert\NotBlank()
     */
    public ?string $query = null;

    /**
     * @var array{sort: 'downloads'|'favers', order: 'asc'|'desc'}|null
     */
    public ?array $orderBys = null;
}
