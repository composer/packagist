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
        try {
            $ids = array();

            foreach ($packages as $package) {
                $ids[] = $package instanceof \Solarium_Document_ReadOnly ? $package->id : $package->getId();
            }

            if (!$ids) {
                return;
            }

            return array(
                'downloads' => $this->get('packagist.download_manager')->getPackagesDownloads($ids),
                'favers' => $this->get('packagist.favorite_manager')->getFaverCounts($ids),
            );
        } catch (\Predis\Connection\ConnectionException $e) {}
    }
}
