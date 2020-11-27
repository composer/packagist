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
    protected $query;

    /**
     * @var array
     */
    protected $orderBys;

    public function setQuery($query)
    {
        $this->query = $query;
    }

    public function getQuery()
    {
        return $this->query;
    }

    public function setOrderBys($orderBys)
    {
        $this->orderBys = $orderBys;
    }

    public function getOrderBys()
    {
        return $this->orderBys;
    }
}