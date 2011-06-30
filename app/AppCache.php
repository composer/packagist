<?php

require_once __DIR__.'/AppKernel.php';

use Symfony\Bundle\FrameworkBundle\HttpCache\HttpCache;

class AppCache extends HttpCache
{
    private $loaded = false;

    protected function forward(Request $request, $raw = false, Response $entry = null)
    {
        if(!$this->loaded) {
            $this->getKernel()->loadClassCache();
            $this->loaded = true;
        }

        return parent::forward($request, $raw, $entry);
    }
}
