<?php

use Symfony\Bundle\FrameworkBundle\HttpCache\HttpCache;

class AppCache extends HttpCache
{
    protected function getOptions()
    {
        return array(
            'debug'                  => false,
            'default_ttl'            => 0,
            'private_headers'        => array(),
            'allow_reload'           => false,
            'allow_revalidate'       => false,
            'stale_while_revalidate' => 60,
            'stale_if_error'         => 86400,
        );
    }
}