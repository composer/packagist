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

namespace App\Service;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ReplicaClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
        /**
         * @var list<string>
         */
        private array $replicaIps,
        private string $internalSecret,
    ) {
    }

    public function uploadMetadata(string $path, string $contents, int $filemtime): void
    {
        $sig = hash_hmac('sha256', $path.$contents.$filemtime, $this->internalSecret);

        $resps = [];
        foreach ($this->replicaIps as $ip) {
            $resps[] = $this->httpClient->request('POST', 'http://'.$ip.'/internal/update-metadata', [
                'headers' => [
                    'Internal-Signature' => $sig,
                    'Host' => 'packagist.org',
                ],
                'body' => [
                    'path' => $path,
                    'contents' => $contents,
                    'filemtime' => $filemtime,
                ],
            ]);
        }

        foreach ($resps as $resp) {
            if ($resp->getStatusCode() !== Response::HTTP_ACCEPTED) {
                throw new TransportException('Invalid response code', $resp->getStatusCode());
            }
        }
    }
}
