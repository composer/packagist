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
        // sent as a raw gzipped body as form-encoding large files would exceed post_max_size
        $gzipped = gzencode($contents, 7);
        if (false === $gzipped) {
            throw new \RuntimeException('Failed gzencoding '.$path);
        }

        $sig = self::sign($path, $gzipped, $filemtime, $this->internalSecret);

        $resps = [];
        foreach ($this->replicaIps as $ip) {
            $resps[$ip] = $this->httpClient->request('POST', 'http://'.$ip.'/internal/update-metadata', [
                'headers' => [
                    'Internal-Signature' => $sig,
                    'Metadata-Path' => $path,
                    'Metadata-Filemtime' => (string) $filemtime,
                    'Content-Type' => 'application/gzip',
                    'Host' => 'packagist.org',
                ],
                'body' => $gzipped,
                'max_redirects' => 0,
            ]);
        }

        foreach ($resps as $ip => $resp) {
            $status = $resp->getStatusCode();
            if ($status !== Response::HTTP_ACCEPTED) {
                throw new TransportException('Invalid response code '.$status.' from replica '.$ip.' for '.$path.' ('.\strlen($gzipped).' bytes gzipped)', $status);
            }
        }
    }

    public static function sign(string $path, string $gzippedContents, int $filemtime, string $secret): string
    {
        return hash_hmac('sha256', \strlen($path).':'.$path.':'.\strlen($gzippedContents).':'.$gzippedContents.':'.$filemtime, $secret);
    }
}
