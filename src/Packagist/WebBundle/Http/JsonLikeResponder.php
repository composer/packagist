<?php

namespace Packagist\WebBundle\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class JsonLikeResponder
{
    /**
     * @param Request $request
     * @param null $data
     * @param int $status
     * @param array $headers
     * @return JsonResponse
     * @throws \Exception
     */
    public static function createResponse(Request $request, $data = null, $status = 200, $headers = array())
    {
        if (! self::isJsonLikeRequest($request)){
            throw new \Exception("Request Format is neither json or jsonp, can't handle it.");
        }

        $response = new JsonResponse($data, $status, $headers);

        if ($request->getRequestFormat() === 'json') {
            return $response;
        }

        if ($request->getRequestFormat() === 'jsonp' && ! $request->query->has('callback')) {
            throw new \Exception("The callback parameter must be included on jsonp requests.");
        }

        $response->setCallback($request->query->get('callback'));
        return $response;
    }

    /**
     * @param Request $request
     * @return bool
     */
    public static function isJsonLikeRequest(Request $request)
    {
        return in_array(strtolower($request->getRequestFormat()), array('json', 'jsonp'));
    }
}
