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

use App\Entity\FidoMetadataFeed;
use App\Security\WebAuthn\FidoMetadataCertificateChainValidator;
use App\Security\WebAuthn\FidoMetadataRepository;
use App\Security\WebAuthn\FidoMetadataUnavailableException;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Serializer\SerializerInterface;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\MetadataService\CertificateChain\PhpCertificateChainValidator;
use Webauthn\MetadataService\Service\MetadataBLOBPayload;

class FidoMetadataRepositoryTest extends IntegrationTestCase
{
    private AttestationAuthority $authority;
    private MockClock $clock;
    /** @var list<string> */
    private array $feeds;
    private int $downloads = 0;
    private int $revocationListRequests = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->authority = new AttestationAuthority();
        $this->clock = new MockClock();
        $this->feeds = [$this->blob()];
    }

    public function testVerifiesAndStoresTheOfficialFeed(): void
    {
        $repository = $this->repository();
        $repository->refresh();
        $statement = $repository->findOneByAAGUID(AttestationAuthority::AAGUID);
        self::assertNotNull($statement);
        self::assertSame('Test hardware key', $statement->description);
        self::assertSame(['hardware', 'secure_element'], $statement->keyProtection);
        self::assertSame('FIDO_CERTIFIED', $repository->findStatusReportsByAAGUID(AttestationAuthority::AAGUID)[0]->status);
        self::assertNull($repository->findOneByAAGUID('unknown'));
        self::assertSame(1, $this->downloads);
        $this->repository()->findOneByAAGUID(AttestationAuthority::AAGUID);
        self::assertSame(1, $this->downloads);
    }

    public function testImportDeserializesThePayloadOnlyOnce(): void
    {
        $actualSerializer = $this->serializer();
        $serializer = $this->createMock(SerializerInterface::class);
        $payloads = 0;
        $serializer->method('serialize')->willReturnCallback($actualSerializer->serialize(...));
        $serializer->expects(self::exactly(2))->method('deserialize')
            ->willReturnCallback(static function ($data, $type, $format, $context = []) use ($actualSerializer, &$payloads) {
                $payloads += $type === MetadataBLOBPayload::class ? 1 : 0;

                return $actualSerializer->deserialize($data, $type, $format, $context);
            });

        $repository = $this->repository($serializer);
        self::assertSame(1, $repository->refresh());
        self::assertSame('Test hardware key', $repository->findOneByAAGUID(AttestationAuthority::AAGUID)?->description);
        self::assertSame(1, $payloads);
    }

    #[TestWith(['signature'])]
    #[TestWith(['root'])]
    #[TestWith(['expired'])]
    #[TestWith(['malformed'])]
    #[TestWith(['number'])]
    public function testRejectsUntrustedOrStaleFeeds(string $failure): void
    {
        if ($failure === 'signature') {
            $parts = explode('.', $this->feeds[0]);
            $parts[1] = rtrim(strtr(base64_encode(str_replace('Test hardware key', 'Fake hardware key', base64_decode(strtr($parts[1], '-_', '+/')))), '+/', '-_'), '=');
            $this->feeds[0] = implode('.', $parts);
        } elseif ($failure === 'root') {
            $this->authority->root = new AttestationAuthority()->root;
        } elseif ($failure === 'expired') {
            $this->feeds[0] = $this->blob(1, '-1 day');
        } elseif ($failure === 'number') {
            $this->feeds[0] = $this->blob(0);
        } else {
            $this->feeds[0] = 'invalid';
        }

        $this->expectException(\RuntimeException::class);
        $this->repository()->refresh();
    }

    public function testFeedSignedByAnUnpinnedRootCannotReplaceTheAcceptedFeed(): void
    {
        $repository = $this->repository();
        self::assertSame(1, $repository->refresh());
        $this->authority = new AttestationAuthority();
        $this->feeds[] = $this->blob(2);

        try {
            $repository->refresh();
            self::fail('Expected the feed signed by an unpinned root to be rejected');
        } catch (\RuntimeException $e) {
            self::assertSame('The FIDO metadata feed could not be verified', $e->getMessage());
            self::assertSame(1, (int) self::getEM()->getConnection()->fetchOne('SELECT sequenceNumber FROM fido_metadata_feed'));
        }
    }

    public function testAnOlderFeedCannotReplaceAnAcceptedFeed(): void
    {
        $this->feeds = [$this->blob(10), $this->blob(9)];
        $repository = $this->repository();
        $repository->refresh();
        self::assertNotNull($repository->findOneByAAGUID(AttestationAuthority::AAGUID));
        $this->clock->sleep(3601);
        $this->expectException(\RuntimeException::class);
        $repository->refresh();
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testExpiredSnapshotIsUnavailable(bool $preflight): void
    {
        $this->feeds = [$this->blob(), 'invalid'];
        $repository = $this->repository();
        $repository->refresh();
        self::assertNotNull($repository->findOneByAAGUID(AttestationAuthority::AAGUID));
        $repository->ensureAvailable();
        $this->clock->sleep(172800);
        $this->expectException(FidoMetadataUnavailableException::class);

        if ($preflight) {
            $repository->ensureAvailable();
        } else {
            $repository->findOneByAAGUID(AttestationAuthority::AAGUID);
        }
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testMissingMetadataDoesNotDownloadDuringEnrollment(bool $preflight): void
    {
        $repository = $this->repository();

        try {
            if ($preflight) {
                $repository->ensureAvailable();
            } else {
                $repository->findOneByAAGUID(AttestationAuthority::AAGUID);
            }

            self::fail('Expected missing metadata to be rejected');
        } catch (FidoMetadataUnavailableException) {
            self::assertSame(0, $this->downloads);
        }
    }

    public function testFailedRefreshKeepsThePreviousVerifiedSnapshot(): void
    {
        $this->feeds = [$this->blob(10), 'invalid'];
        $repository = $this->repository();
        $repository->refresh();

        try {
            $repository->refresh();
            self::fail('Expected invalid replacement feed to be rejected');
        } catch (\RuntimeException) {
            self::assertSame('Test hardware key', $repository->findOneByAAGUID(AttestationAuthority::AAGUID)?->description);
        }
    }

    public function testUnavailableSigningCertificateRevocationListPreservesTheVerifiedFeed(): void
    {
        $repository = $this->repository();
        self::assertSame(1, $repository->refresh());
        $this->authority = new AttestationAuthority(withRevocationList: true);
        $this->clock = new MockClock();
        $this->feeds[] = $this->blob(2);

        try {
            $this->repository()->refresh();
            self::fail('Expected the feed with unavailable certificate revocation information to be rejected');
        } catch (\RuntimeException $e) {
            self::assertSame('The FIDO metadata feed could not be verified', $e->getMessage());
            self::assertSame(1, $this->revocationListRequests);
            self::assertSame(1, (int) self::getEM()->getConnection()->fetchOne('SELECT sequenceNumber FROM fido_metadata_feed'));
            self::assertNotNull($repository->findOneByAAGUID(AttestationAuthority::AAGUID));
        }
    }

    public function testAReplacementFeedRemovesModelsNoLongerListed(): void
    {
        $other = '1be3c47c-2338-42c0-9159-482b189d7862';
        $this->feeds = [$this->blob(1), $this->blob(2, '+2 days', $other)];
        $repository = $this->repository();
        $repository->refresh();
        $repository->refresh();
        self::assertNull($repository->findOneByAAGUID(AttestationAuthority::AAGUID));
        self::assertNotNull($repository->findOneByAAGUID($other));
        self::assertSame(2, (int) self::getEM()->getConnection()->fetchOne('SELECT sequenceNumber FROM fido_metadata_feed'));
        self::assertSame(1, (int) self::getEM()->getConnection()->fetchOne('SELECT COUNT(*) FROM fido_metadata_feed'));
        self::assertNotNull(self::getEM()->find(FidoMetadataFeed::class, 2));
        self::assertNull(self::getEM()->find(FidoMetadataFeed::class, 1));
        self::assertSame(1, (int) self::getEM()->getConnection()->fetchOne('SELECT COUNT(*) FROM fido_metadata_entry'));
    }

    public function testUnchangedSequenceLeavesTheFeedAndEntriesUntouched(): void
    {
        $other = '1be3c47c-2338-42c0-9159-482b189d7862';
        $this->feeds = [$this->blob(10), $this->blob(10, '+3 days', $other)];
        $repository = $this->repository();
        $repository->refresh();
        $connection = self::getEM()->getConnection();
        $header = $connection->fetchAllAssociative('SELECT * FROM fido_metadata_feed');
        $entries = $connection->fetchAllAssociative('SELECT * FROM fido_metadata_entry');
        $this->clock->sleep(60);
        $actualSerializer = $this->serializer();
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('deserialize')->willReturnCallback($actualSerializer->deserialize(...));
        $serializer->expects(self::never())->method('serialize');

        self::assertSame(10, $this->repository($serializer)->refresh());
        self::assertSame(2, $this->downloads);
        self::assertSame($header, $connection->fetchAllAssociative('SELECT * FROM fido_metadata_feed'));
        self::assertSame($entries, $connection->fetchAllAssociative('SELECT * FROM fido_metadata_entry'));
    }

    public function testFailedDatabaseReplacementKeepsThePreviousFeedAndEntries(): void
    {
        $this->feeds = [$this->blob(10), $this->blob(11)];
        $repository = $this->repository();
        $repository->refresh();
        $actualSerializer = $this->serializer();
        $serializer = $this->createMock(SerializerInterface::class);
        $serializer->method('deserialize')->willReturnCallback($actualSerializer->deserialize(...));
        $failure = new \RuntimeException('Serialization failed during replacement');
        $serializer->expects(self::once())->method('serialize')->willThrowException($failure);

        try {
            $this->repository($serializer)->refresh();
            self::fail('Expected the replacement to fail after deleting the old entries');
        } catch (\RuntimeException $exception) {
            self::assertSame($failure, $exception);
            self::assertSame('Test hardware key', $repository->findOneByAAGUID(AttestationAuthority::AAGUID)?->description);
            self::assertSame(10, (int) self::getEM()->getConnection()->fetchOne('SELECT sequenceNumber FROM fido_metadata_feed'));
        }
    }

    public function testAnUnrelatedModelIsNotDeserializedDuringLookup(): void
    {
        $repository = $this->repository();
        $repository->refresh();
        self::getEM()->getConnection()->insert('fido_metadata_entry', [
            'aaguid' => '1be3c47c-2338-42c0-9159-482b189d7862',
            'statement' => '{}', 'statusReports' => '[]',
        ]);
        self::assertNotNull($repository->findOneByAAGUID(AttestationAuthority::AAGUID));
        self::assertCount(1, $repository->findStatusReportsByAAGUID(AttestationAuthority::AAGUID));
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testDatabaseOutageIsReportedAsUnavailableMetadata(bool $preflight): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('createQueryBuilder')->willReturn(new QueryBuilder($connection));
        $connection->method('executeQuery')->willThrowException(new class('Unavailable') extends \RuntimeException implements \Doctrine\DBAL\Exception {});
        $repository = $this->repository(connection: $connection);
        $this->expectException(FidoMetadataUnavailableException::class);

        if ($preflight) {
            $repository->ensureAvailable();
        } else {
            $repository->findOneByAAGUID(AttestationAuthority::AAGUID);
        }
    }

    private function repository(?SerializerInterface $serializer = null, ?Connection $connection = null): FidoMetadataRepository
    {
        $client = new MockHttpClient(function (string $method, string $url): MockResponse {
            if ($url === AttestationAuthority::CRL_URL) {
                ++$this->revocationListRequests;

                return new MockResponse('', ['http_code' => 503]);
            }

            if (str_contains($url, 'rootr46')) {
                return new MockResponse($this->authority->der($this->authority->root));
            }

            return new MockResponse($this->feeds[$this->downloads++]);
        });

        $validator = new FidoMetadataCertificateChainValidator(
            new PhpCertificateChainValidator($client, $this->clock, allowFailures: false),
            hash('sha256', $this->authority->der($this->authority->root)),
        );

        return new FidoMetadataRepository($connection ?? self::getEM()->getConnection(), $client, $validator, $serializer ?? self::getService(SerializerInterface::class), $this->clock);
    }

    private function serializer(): SerializerInterface
    {
        return new WebauthnSerializerFactory(new AttestationStatementSupportManager())->create();
    }

    private function blob(int $number = 1, string $expiry = '+2 days', string $aaguid = AttestationAuthority::AAGUID): string
    {
        $statement = $this->authority->statement();
        $statement->aaguid = $aaguid;

        return $this->authority->blob([
            'no' => $number,
            'nextUpdate' => $this->clock->now()->modify($expiry)->format('Y-m-d'),
            'entries' => [[
                'aaguid' => $aaguid,
                'timeOfLastStatusChange' => '2026-01-01',
                'metadataStatement' => json_decode($this->serializer()->serialize($statement, 'json'), true),
                'statusReports' => [['status' => 'FIDO_CERTIFIED']],
            ]],
        ]);
    }
}
