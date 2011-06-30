<?php

require_once __DIR__.'/AppKernel.php';

use Symfony\Bundle\FrameworkBundle\HttpCache\HttpCache;

class AppCache extends HttpCache
{
    protected function forward(Request $request, $raw = false, Response $entry = null)
    {
        $this->getKernel()->loadClassCache();

        return parent::forward($request, $raw, $entry);
    }
}
