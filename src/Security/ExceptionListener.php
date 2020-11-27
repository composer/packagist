<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Http\Firewall\ExceptionListener as BaseExceptionListener;

class ExceptionListener extends BaseExceptionListener
{
    protected function setTargetPath(Request $request)
    {
        // Do not save target path for oauth registration
        if (preg_match('{^/connect/registration}', $request->getPathInfo())) {
            return;
        }

        parent::setTargetPath($request);
    }
}
