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

namespace App\Tests\Security\WebAuthn;

use CBOR\ByteStringObject;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use Webauthn\MetadataService\Statement\MetadataStatement;

class AttestationAuthority
{
    public const string AAGUID = '9e458c98-0115-41f4-bc7b-b7ce86747111';
    public string $root = '';
    public string $certificate = '';
    private \OpenSSLAsymmetricKey $key;

    public function __construct()
    {
        $config = tempnam(sys_get_temp_dir(), 'webauthn-cert-');
        file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[ca]\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,keyCertSign,cRLSign\nsubjectKeyIdentifier=hash\n[leaf]\nbasicConstraints=critical,CA:FALSE\nkeyUsage=digitalSignature\nsubjectKeyIdentifier=hash\nauthorityKeyIdentifier=keyid,issuer\n");

        try {
            $options = ['config' => $config, 'digest_alg' => 'sha256'];
            $rootKey = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $rootRequest = openssl_csr_new(['commonName' => 'Test FIDO root'], $rootKey, $options);
            $root = openssl_csr_sign($rootRequest, null, $rootKey, 3650, $options + ['x509_extensions' => 'ca']);
            openssl_x509_export($root, $this->root);
            $this->key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $request = openssl_csr_new(['countryName' => 'CH', 'organizationName' => 'Test key manufacturer', 'organizationalUnitName' => 'Authenticator Attestation', 'commonName' => 'Test hardware key'], $this->key, $options);
            $certificate = openssl_csr_sign($request, $root, $rootKey, 3650, $options + ['x509_extensions' => 'leaf']);
            openssl_x509_export($certificate, $this->certificate);
        } finally {
            unlink($config);
        }
    }

    /** @return array<string, mixed> */
    public function attest(string $contents): array
    {
        openssl_sign($contents, $signature, $this->key, \OPENSSL_ALGO_SHA256);

        return ['alg' => -7, 'sig' => new ByteStringObject($signature), 'x5c' => [new ByteStringObject($this->der($this->certificate))]];
    }

    public function statement(): MetadataStatement
    {
        return new MetadataStatement('Test hardware key', 1, 'fido2', 3, [], ['secp256r1_ecdsa_sha256_raw'], ['cose'], ['basic_full'], [], [], [], [base64_encode($this->der($this->root))], aaguid: self::AAGUID, keyProtection: ['hardware', 'secure_element']);
    }

    /** @param array<string, mixed> $payload */
    public function blob(array $payload): string
    {
        openssl_pkey_export($this->key, $pem);
        $key = JWKFactory::createFromKey($pem);
        $jws = new JWSBuilder(new AlgorithmManager([new ES256()]))
            ->create()
            ->withPayload(json_encode($payload, \JSON_THROW_ON_ERROR))
            ->addSignature($key, ['alg' => 'ES256', 'x5c' => [base64_encode($this->der($this->certificate))]])
            ->build();

        return new CompactSerializer()->serialize($jws);
    }

    public function der(string $pem): string
    {
        return base64_decode(preg_replace('/-----[^-]+-----|\\s/', '', $pem), true);
    }
}
