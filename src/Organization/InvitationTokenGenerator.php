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

namespace App\Organization;

/**
 * Generates and hashes the single-use invitation link token. The token is a 256-bit cryptographically
 * random value shown only in the emailed link; only its SHA-256 hash is persisted (in the read model),
 * never the raw token. Verification re-hashes the URL token and compares with {@see hash_equals}.
 */
final class InvitationTokenGenerator
{
    /**
     * @return array{raw: string, hash: string} the raw token for the link and the hash to store
     */
    public function generate(): array
    {
        $raw = bin2hex(random_bytes(32));

        return ['raw' => $raw, 'hash' => $this->hash($raw)];
    }

    public function hash(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Constant-time comparison of a URL token against a stored hash.
     */
    public function matches(string $rawToken, string $storedHash): bool
    {
        return hash_equals($storedHash, $this->hash($rawToken));
    }
}
