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

namespace App\Model;

use App\Entity\Package;
use App\Entity\User;
use Pagerfanta\Adapter\AdapterInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @template-implements AdapterInterface<Package>
 */
class RedisAdapter implements AdapterInterface
{
    public function __construct(private FavoriteManager $model, private User $instance)
    {
    }

    public function getNbResults(): int
    {
        return $this->model->getFavoriteCount($this->instance);
    }

    public function getSlice(int $offset, int $length): iterable
    {
        return $this->model->getFavorites($this->instance, $length, $offset);
    }
}
