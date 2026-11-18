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
use CBOR\Encoder;
use CBOR\MapObject;

class VirtualAuthenticator
{
    private \OpenSSLAsymmetricKey $key;
    public string $id;
    public string $origin = 'https://packagist.wip';
    public string $rpId = 'packagist.wip';
    public int $flags = 1;
    public int $counter = 1;
    public bool $corruptSignature = false;

    public function __construct()
    {
        $this->key = openssl_pkey_new(['private_key_type' => \OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->id = random_bytes(32);
    }

    /** @param array<string, mixed> $options */
    public function register(array $options, bool $selfAttestation = false): string
    {
        $details = openssl_pkey_get_details($this->key);
        $cose = new Encoder()->encode([
            1 => 2, 3 => -7, -1 => 1,
            -2 => new ByteStringObject(str_pad($details['ec']['x'], 32, "\0", \STR_PAD_LEFT)),
            -3 => new ByteStringObject(str_pad($details['ec']['y'], 32, "\0", \STR_PAD_LEFT)),
        ]);
        $authData = $this->authData(true).str_repeat("\0", 16).pack('n', \strlen($this->id)).$this->id.$cose;
        $clientData = $this->clientData($options, 'webauthn.create');
        $statement = $selfAttestation
            ? ['alg' => -7, 'sig' => new ByteStringObject($this->sign($authData.hash('sha256', $clientData, true)))]
            : new MapObject();
        $attestation = new Encoder()->encode([
            'fmt' => $selfAttestation ? 'packed' : 'none',
            'authData' => new ByteStringObject($authData),
            'attStmt' => $statement,
        ]);

        return $this->response(['clientDataJSON' => self::encode($clientData), 'attestationObject' => self::encode($attestation), 'transports' => ['usb']]);
    }

    /** @param array<string, mixed> $options */
    public function authenticate(array $options): string
    {
        $clientData = $this->clientData($options, 'webauthn.get');
        $authData = $this->authData(false);

        return $this->response([
            'clientDataJSON' => self::encode($clientData),
            'authenticatorData' => self::encode($authData),
            'signature' => self::encode($this->sign($authData.hash('sha256', $clientData, true))),
        ]);
    }

    private function authData(bool $registering): string
    {
        return hash('sha256', $this->rpId, true).\chr($this->flags | ($registering ? 64 : 0)).pack('N', $registering ? 0 : $this->counter);
    }

    /** @param array<string, mixed> $options */
    private function clientData(array $options, string $type): string
    {
        return json_encode(['type' => $type, 'challenge' => $options['challenge'], 'origin' => $this->origin, 'crossOrigin' => false], \JSON_THROW_ON_ERROR);
    }

    private function sign(string $contents): string
    {
        if ($this->corruptSignature) {
            return random_bytes(64);
        }
        openssl_sign($contents, $signature, $this->key, \OPENSSL_ALGO_SHA256);

        return $signature;
    }

    /** @param array<string, mixed> $response */
    private function response(array $response): string
    {
        return json_encode(['id' => self::encode($this->id), 'rawId' => self::encode($this->id), 'type' => 'public-key', 'response' => $response], \JSON_THROW_ON_ERROR);
    }

    private static function encode(string $contents): string
    {
        return rtrim(strtr(base64_encode($contents), '+/', '-_'), '=');
    }
}
