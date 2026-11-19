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

use Webauthn\MetadataService\CertificateChain\CertificateChainValidator;

final class FidoMetadataCertificateChainValidator implements CertificateChainValidator
{
    // GlobalSign Root R46, valid until 2046-03-20
    private const string ROOT_SHA256 = '4fa3126d8d3a11d1c4855a4f807cbad6cf919d3a5a88b03bea2c6372d93c40c9';

    public function __construct(
        private CertificateChainValidator $validator,
        private string $rootFingerprint = self::ROOT_SHA256,
    ) {
    }

    public function check(array $untrustedCertificates, array $trustedCertificates): void
    {
        $root = $trustedCertificates[0] ?? null;

        if (\count($trustedCertificates) !== 1 || $root === null || openssl_x509_fingerprint($root, 'sha256') !== $this->rootFingerprint) {
            throw new \UnexpectedValueException('The FIDO metadata signing root does not match the pinned GlobalSign Root R46 fingerprint');
        }

        $this->validator->check($untrustedCertificates, $trustedCertificates);
    }
}
