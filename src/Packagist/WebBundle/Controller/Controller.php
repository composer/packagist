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

namespace Packagist\WebBundle\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\Controller as BaseController;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Controller extends BaseController
{
    protected function getPackagesMetadata($packages)
    {
        $metadata = null;
        try {
            $dlKeys = array();
            foreach ($packages as $package) {
                $id = $package instanceof \Solarium_Document_ReadOnly ? $package->id : $package->getId();
                $dlKeys[$id] = 'dl:'.$id;
            }
            if (!$dlKeys) {
                return $metadata;
            }
            $res = array_map('intval', $this->get('snc_redis.default')->mget(array_values($dlKeys)));

            $metadata = array(
                'downloads' => array_combine(array_keys($dlKeys), $res),
                'favers' => $this->get('packagist.favorite_manager')->getFaverCounts(array_keys($dlKeys)),
            );
        } catch (\Predis\Connection\ConnectionException $e) {}

        return $metadata;
    }
}
