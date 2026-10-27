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

namespace App\Controller;

use App\Service\ReplicaClient;
use Composer\Pcre\Preg;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class InternalController extends Controller
{
    public function __construct(
        private string $internalSecret,
        private string $metadataDir,
        private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/internal/update-metadata', name: 'internal_update_metadata', defaults: ['_format' => 'json'], methods: ['POST'])]
    public function updateMetadataAction(Request $req): Response
    {
        if ($req->getClientIp() === null || !IpUtils::isPrivateIp($req->getClientIp())) {
            $this->logger->error('Non-internal IP on internal route', ['ip' => $req->getClientIp()]);
            throw $this->createAccessDeniedException();
        }

        $path = (string) $req->headers->get('Metadata-Path');
        $filemtime = (int) $req->headers->get('Metadata-Filemtime');
        $gzipped = $req->getContent();
        $sig = (string) $req->headers->get('Internal-Signature');

        if (!hash_equals(ReplicaClient::sign($path, $gzipped, $filemtime, $this->internalSecret), $sig)) {
            $this->logger->error('Invalid signature', [
                'path' => $path,
                'filemtime' => $filemtime,
                'sig' => $sig,
                'content_length_header' => $req->headers->get('Content-Length'),
                'content_length_received' => \strlen($gzipped),
            ]);

            return new Response('Invalid signature', Response::HTTP_FORBIDDEN);
        }

        if (str_contains($path, '..') || !Preg::isMatch('{^(packages\.json|p2/[^/]+/[^/]+\.json)$}', $path)) {
            $this->logger->error('Invalid path received for metadata write: '.$path);

            return new Response('Invalid path', Response::HTTP_FORBIDDEN);
        }

        $path = $this->metadataDir.'/'.$path.'.gz';
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), recursive: true);
        }
        file_put_contents($path.'.tmp', $gzipped);
        touch($path.'.tmp', $filemtime);
        rename($path.'.tmp', $path);

        return new Response('OK', Response::HTTP_ACCEPTED);
    }
}
