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

namespace App\Model;

use Pagerfanta\Adapter\AdapterInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RedisAdapter implements AdapterInterface
{
    protected $model;
    protected $instance;
    protected $fetchMethod;
    protected $countMethod;

    public function __construct($model, $instance, $fetchMethod, $countMethod)
    {
        $this->model = $model;
        $this->instance = $instance;
        $this->fetchMethod = $fetchMethod;
        $this->countMethod = $countMethod;
    }

    /**
     * {@inheritDoc}
     */
    public function getNbResults()
    {
        return $this->model->{$this->countMethod}($this->instance);
    }

    /**
     * {@inheritDoc}
     */
    public function getSlice($offset, $length)
    {
        return $this->model->{$this->fetchMethod}($this->instance, $length, $offset);
    }
}
