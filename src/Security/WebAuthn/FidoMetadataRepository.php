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

use App\Service\Locker;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Webauthn\MetadataService\CertificateChain\CertificateChainValidator;
use Webauthn\MetadataService\MetadataStatementRepository;
use Webauthn\MetadataService\Service\FidoAllianceCompliantMetadataService;
use Webauthn\MetadataService\Statement\MetadataStatement;
use Webauthn\MetadataService\Statement\StatusReport;
use Webauthn\MetadataService\StatusReportRepository;

/** @phpstan-type Snapshot array{number: int, expires: \DateTimeImmutable, statements: array<string, MetadataStatement>, reports: array<string, list<StatusReport>>} */
class FidoMetadataRepository implements MetadataStatementRepository, StatusReportRepository
{
    private const string FEED_URL = 'https://mds3.fidoalliance.org/';
    // FIDO publishes its MDS signing root separately from the signed feed
    private const string ROOT_URL = 'https://secure.globalsign.com/cacert/rootr46.crt';

    public function __construct(
        private Connection $connection,
        private HttpClientInterface $httpClient,
        private CertificateChainValidator $certificateValidator,
        private SerializerInterface $serializer,
        private ClockInterface $clock,
        private Locker $locker,
    ) {
    }

    public function findOneByAAGUID(string $aaguid): ?MetadataStatement
    {
        $entry = $this->findEntry($aaguid);

        return $entry['statement'] === null ? null : $this->serializer->deserialize($entry['statement'], MetadataStatement::class, 'json');
    }

    public function findStatusReportsByAAGUID(string $aaguid): array
    {
        $entry = $this->findEntry($aaguid);

        return $entry['statusReports'] === null ? [] : $this->serializer->deserialize($entry['statusReports'], StatusReport::class.'[]', 'json');
    }

    public function refresh(): int
    {
        if (!$this->locker->lockCommand(self::class)) {
            throw new \RuntimeException('A FIDO metadata import is already running');
        }

        try {
            $snapshot = $this->download();
            $this->connection->transactional(fn () => $this->replace($snapshot));

            return $snapshot['number'];
        } finally {
            $this->locker->unlockCommand(self::class);
        }
    }

    /** @return array{statement: ?string, statusReports: ?string} */
    private function findEntry(string $aaguid): array
    {
        // Read the header and the requested model in one snapshot during a concurrent import
        $entry = $this->connection->fetchAssociative('SELECT f.nextUpdate, e.statement, e.statusReports FROM fido_metadata_feed f LEFT JOIN fido_metadata_entry e ON e.aaguid = :aaguid ORDER BY f.sequenceNumber DESC LIMIT 1', ['aaguid' => $aaguid]);

        if ($entry === false) {
            throw new \RuntimeException('Import the FIDO metadata feed before enrolling attested keys');
        }

        if (new \DateTimeImmutable($entry['nextUpdate'], new \DateTimeZone('UTC')) <= $this->clock->now()) {
            throw new \RuntimeException('The FIDO metadata feed has expired');
        }

        return ['statement' => $entry['statement'], 'statusReports' => $entry['statusReports']];
    }

    /** @param Snapshot $snapshot */
    private function replace(array $snapshot): void
    {
        $previousNumber = $this->connection->fetchOne('SELECT sequenceNumber FROM fido_metadata_feed ORDER BY sequenceNumber DESC LIMIT 1 FOR UPDATE');

        if ($previousNumber !== false && $snapshot['number'] < $previousNumber) {
            throw new \RuntimeException('The FIDO metadata feed is older than the imported feed');
        }

        if ($previousNumber !== false && $snapshot['number'] === (int) $previousNumber) {
            return;
        }

        $this->connection->executeStatement('DELETE FROM fido_metadata_entry');

        foreach ($snapshot['statements'] as $aaguid => $statement) {
            $this->connection->insert('fido_metadata_entry', [
                'aaguid' => $aaguid,
                'statement' => $this->serializer->serialize($statement, 'json'),
                'statusReports' => $this->serializer->serialize($snapshot['reports'][$aaguid], 'json'),
            ]);
        }

        $header = [
            'sequenceNumber' => $snapshot['number'],
            'nextUpdate' => $snapshot['expires']->format('Y-m-d H:i:s'),
            'importedAt' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ];

        // Keep only the current feed, replacing its identity with the signed sequence number
        $this->connection->executeStatement('DELETE FROM fido_metadata_feed');
        $this->connection->insert('fido_metadata_feed', $header);
    }

    /** @return Snapshot */
    private function download(): array
    {
        $client = $this->httpClient->withOptions(['timeout' => 10, 'max_duration' => 30]);
        $serializer = new FidoMetadataSerializer($this->serializer);
        $service = new FidoAllianceCompliantMetadataService($client, self::FEED_URL, [], $this->certificateValidator, self::ROOT_URL, $serializer);
        $aaguids = iterator_to_array($service->list());

        // The library swallows verification errors and returns an empty list
        if ($aaguids === []) {
            throw new \RuntimeException('The FIDO metadata feed could not be verified');
        }

        // Inspect expiry only after the library has verified and deserialized the signed BLOB
        $blob = $serializer->getHeader();
        $expires = \DateTimeImmutable::createFromFormat('!Y-m-d', $blob->nextUpdate, new \DateTimeZone('UTC'));

        if ($blob->no < 1 || $expires === false || $expires->format('Y-m-d') !== $blob->nextUpdate || $expires <= $this->clock->now()) {
            throw new \RuntimeException('The FIDO metadata feed is stale');
        }

        $statements = $reports = [];

        foreach ($aaguids as $aaguid) {
            $statements[$aaguid] = $service->get($aaguid);
            // Model icons are not needed for attestation checks or our key list
            $statements[$aaguid]->icon = null;
            $reports[$aaguid] = array_values(iterator_to_array($service->getStatusReports($aaguid)));
        }

        return ['number' => $blob->no, 'expires' => $expires, 'statements' => $statements, 'reports' => $reports];
    }
}
