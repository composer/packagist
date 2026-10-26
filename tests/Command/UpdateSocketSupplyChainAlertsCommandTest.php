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

namespace App\Tests\Command;

use App\Command\UpdateSocketSupplyChainAlertsCommand;
use App\Entity\SocketSupplyChainAlert;
use App\Entity\SocketSupplyChainAlertRepository;
use App\Service\Locker;
use App\Socket\SocketThreat;
use App\Socket\SocketThreatFeed;
use App\Socket\SupplyChainAlertType;
use App\Tests\IntegrationTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class UpdateSocketSupplyChainAlertsCommandTest extends IntegrationTestCase
{
    public function testStoresConfirmedAlerts(): void
    {
        $this->store(new SocketSupplyChainAlert('vendor/stale', '1.0.0', SupplyChainAlertType::TELEMETRY, null));

        $tester = $this->createTester([
            'typo' => [
                new SocketThreat('vendor/typo', '1.0.0', 'typosquat', false, 'https://socket.dev/composer/package/vendor/typo/alerts/1.0.0'),
                new SocketThreat('vendor/possible', '1.0.0', 'possible_typosquat', false, null),
                new SocketThreat('vendor/unreviewed', '1.0.0', 'typosquat', true, null),
            ],
            'obf' => [
                // one threat per obfuscated file, but one alert per version
                new SocketThreat('vendor/obfuscated', '2.0.0', 'obfuscated', false, 'https://socket.dev/a.php'),
                new SocketThreat('vendor/obfuscated', '2.0.0', 'obfuscated', false, 'https://socket.dev/b.php'),
            ],
            'spy' => [],
            'joke' => [new SocketThreat('vendor/troll', '3.0.0', 'troll', false, null)],
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));

        $this->assertSame([
            ['vendor/obfuscated', '2.0.0', 'obfuscated', 'https://socket.dev/a.php'],
            ['vendor/troll', '3.0.0', 'troll', null],
            ['vendor/typo', '1.0.0', 'typosquat', 'https://socket.dev/composer/package/vendor/typo/alerts/1.0.0'],
        ], $this->fetchStoredAlerts());
    }

    public function testStoresVersionSpellingsTheDatabaseConsidersEqualOnce(): void
    {
        $tester = $this->createTester([
            'typo' => [
                new SocketThreat('vendor/typo', '1.0.0-RC1', 'typosquat', false, null),
                new SocketThreat('vendor/typo', '1.0.0-rc1', 'typosquat', false, null),
                new SocketThreat('vendor/typo', 'dev-'.str_repeat('a', 300), 'typosquat', false, null),
            ],
            'obf' => [],
            'spy' => [],
            'joke' => [],
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame([['vendor/typo', '1.0.0-RC1', 'typosquat', null]], $this->fetchStoredAlerts());
    }

    public function testStoresTheEarliestReportOfAVersion(): void
    {
        $tester = $this->createTester([
            'typo' => [],
            'obf' => [
                new SocketThreat('vendor/obfuscated', '1.0.0', 'obfuscated', false, 'https://socket.dev/later.php', new \DateTimeImmutable('2026-03-01 00:00:00')),
                new SocketThreat('vendor/obfuscated', '1.0.0', 'obfuscated', false, 'https://socket.dev/earliest.php', new \DateTimeImmutable('2026-01-01 00:00:00')),
            ],
            'spy' => [],
            'joke' => [],
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]));
        $this->assertSame([['vendor/obfuscated', '1.0.0', 'obfuscated', 'https://socket.dev/earliest.php']], $this->fetchStoredAlerts());
        $this->assertSame('2026-01-01 00:00:00', self::getEM()->getConnection()->fetchOne('SELECT reportedAt FROM socket_supply_chain_alert'));
    }

    public function testKeepsExistingAlertsWhenTheFeedFails(): void
    {
        $this->store(new SocketSupplyChainAlert('vendor/kept', '1.0.0', SupplyChainAlertType::TELEMETRY, null));

        $tester = $this->createTester([
            'typo' => [new SocketThreat('vendor/typo', '1.0.0', 'typosquat', false, null)],
            'obf' => null,
            'spy' => [],
            'joke' => [],
        ]);

        $this->assertSame(Command::FAILURE, $tester->execute([]));
        $this->assertSame([['vendor/kept', '1.0.0', 'telemetry', null]], $this->fetchStoredAlerts());
    }

    /**
     * @param array<string, list<SocketThreat>|null> $threatsByFilter
     */
    private function createTester(array $threatsByFilter): CommandTester
    {
        $feed = $this->createStub(SocketThreatFeed::class);
        $feed->method('getThreats')->willReturnCallback(static fn (string $filter) => $threatsByFilter[$filter]);

        return new CommandTester(new UpdateSocketSupplyChainAlertsCommand(
            $feed,
            self::getService(SocketSupplyChainAlertRepository::class),
            self::getService(Locker::class),
        ));
    }

    /**
     * @return list<array{string, string, string, string|null}>
     */
    private function fetchStoredAlerts(): array
    {
        /* @var list<array{string, string, string, string|null}> */
        return self::getEM()->getConnection()->fetchAllNumeric(
            'SELECT packageName, version, type, link FROM socket_supply_chain_alert ORDER BY packageName'
        );
    }
}
