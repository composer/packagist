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
            $this->logger->error('Non-internal IP on internal IP');
            throw $this->createAccessDeniedException();
        }

        $path = $req->request->getString('path');
        $contents = $req->request->getString('contents');
        $filemtime = $req->request->getInt('filemtime');
        $sig = (string) $req->headers->get('Internal-Signature');

        $expectedSig = hash_hmac('sha256', $path.$contents.$filemtime, $this->internalSecret);
        if (!hash_equals($expectedSig, $sig)) {
            $this->logger->error('Invalid signature', ['contents' => $contents, 'path' => $path, 'filemtime' => $filemtime, 'sig' => $sig]);
            throw $this->createAccessDeniedException();
        }

        $gzipped = gzencode($contents, 7);
        if (false === $gzipped) {
            throw new \RuntimeException('Failed gzencoding '.$contents);
        }

        $path = $this->metadataDir . '/' . $path;
        file_put_contents($path.'.tmp', $gzipped);
        touch($path.'.tmp', $filemtime);
        rename($path.'.tmp', $path);

        return new Response('OK', Response::HTTP_ACCEPTED);
    }
}
