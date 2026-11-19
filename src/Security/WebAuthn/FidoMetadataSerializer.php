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

namespace App\Security\WebAuthn;

use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\MetadataService\Service\MetadataBLOBPayload;

final class FidoMetadataSerializer implements SerializerInterface
{
    public private(set) ?MetadataBLOBPayload $header = null;

    public function __construct(private SerializerInterface $serializer)
    {
    }

    public function serialize(mixed $data, string $format, array $context = []): string
    {
        return $this->serializer->serialize($data, $format, $context);
    }

    public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed
    {
        $result = $this->serializer->deserialize($data, $type, $format, $context);

        if ($result instanceof MetadataBLOBPayload) {
            // Keep the header the loader does not expose, without retaining its entries
            $this->header = new MetadataBLOBPayload($result->no, $result->nextUpdate);
        }

        return $result;
    }
}
