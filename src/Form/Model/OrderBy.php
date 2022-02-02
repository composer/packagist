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

/**
 * @author Benjamin Michalski <benjamin.michalski@gmail.com>
 */
class OrderBy
{
    /**
     * @var string
     */
    public string $sort;

    /**
     * @var string
     */
    public string $order;
}
