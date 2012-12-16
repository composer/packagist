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

namespace Packagist\WebBundle\Model;

use Pagerfanta\Adapter\AdapterInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class FixedAdapter implements AdapterInterface
{
    protected $data;
    protected $count;

    public function __construct($data, $count)
    {
        $this->data = $data;
        $this->count = $count;
    }

    /**
     * {@inheritDoc}
     */
    public function getNbResults()
    {
        return $this->count;
    }

    /**
     * {@inheritDoc}
     */
    public function getSlice($offset, $length)
    {
        return $this->data;
    }
}
