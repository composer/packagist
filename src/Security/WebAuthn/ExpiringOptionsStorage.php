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

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Webauthn\Bundle\Security\Storage\Item;
use Webauthn\Bundle\Security\Storage\OptionsStorage;
use Webauthn\Bundle\Security\Storage\SessionStorage;

final class ExpiringOptionsStorage implements OptionsStorage
{
    private const string SESSION_KEY = 'webauthn.pending_options';
    private const int LIFETIME = 120;

    public function __construct(
        private SessionStorage $storage,
        private RequestStack $requests,
        private ClockInterface $clock,
    ) {
    }

    public function store(Item $item): void
    {
        $options = $item->getPublicKeyCredentialOptions();
        $session = $this->requests->getSession();
        $pending = $session->get(self::SESSION_KEY, []);
        $now = $this->clock->now()->getTimestamp();

        // Keep one challenge per ceremony type and give the browser prompt time to submit its response
        foreach ($pending as $type => $entry) {
            if ($type === $options::class || $entry['expires'] <= $now) {
                try {
                    $this->storage->get($entry['challenge']);
                } catch (BadRequestHttpException) {
                    // The underlying session entry is already gone
                }
                unset($pending[$type]);
            }
        }

        $this->storage->store($item);
        $pending[$options::class] = ['challenge' => $options->challenge, 'expires' => $now + self::LIFETIME];
        $session->set(self::SESSION_KEY, $pending);
    }

    public function get(string $challenge): Item
    {
        $session = $this->requests->getSession();
        $pending = $session->get(self::SESSION_KEY, []);

        foreach ($pending as $type => $entry) {
            if ($entry['challenge'] !== $challenge) {
                continue;
            }

            unset($pending[$type]);
            $session->set(self::SESSION_KEY, $pending);
            try {
                $item = $this->storage->get($challenge);
            } catch (BadRequestHttpException $e) {
                throw new ExpiredWebAuthnChallengeException($e);
            }

            if ($entry['expires'] > $this->clock->now()->getTimestamp()) {
                return $item;
            }

            break;
        }

        throw new ExpiredWebAuthnChallengeException();
    }
}
