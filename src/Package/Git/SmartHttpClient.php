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

namespace App\Package\Git;

use Composer\Downloader\MaxFileSizeExceededException;
use Composer\Downloader\TransportException;
use Composer\Pcre\Preg;
use Composer\Util\Http\CurlResponse;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;

/**
 * Client side of git's smart HTTP protocol (gitprotocol-http(5)), protocol v2 with a v0 fallback, read-only
 */
final class SmartHttpClient
{
    // some servers only answer with the smart protocol to git user agents
    private const string USER_AGENT = 'git/2.0 (Packagist.org; +https://packagist.org/about)';
    private const string AGENT = 'git/2.0-packagist';
    private const string SERVICE_SUFFIX = '/info/refs?service=git-upload-pack';

    private ?string $serviceUrl = null;
    private int $version = 0;
    /** @var array<string, string> */
    private array $capabilities = [];
    private ObjectFormat $objectFormat = ObjectFormat::Sha1;
    /** @var list<array{oid: string, name: string, peeled: ?string, symrefTarget: ?string}> refs from a v0 advertisement */
    private array $advertisedRefs = [];

    public function __construct(
        private readonly HttpDownloader $httpDownloader,
        private readonly string $url,
        private readonly int $maxResponseSize = 32 * 1024 * 1024,
    ) {
        if (!str_starts_with($url, 'https://')) {
            throw new UnsupportedRepositoryException('Only https:// repository URLs are supported, got '.$url);
        }
    }

    public function getObjectFormat(): ObjectFormat
    {
        $this->discover();

        return $this->objectFormat;
    }

    public function supportsShallow(): bool
    {
        $this->discover();

        return $this->version === 2 ? \in_array('shallow', $this->getFetchFeatures(), true) : isset($this->capabilities['shallow']);
    }

    public function supportsFilter(): bool
    {
        $this->discover();

        return $this->version === 2 ? \in_array('filter', $this->getFetchFeatures(), true) : isset($this->capabilities['filter']);
    }

    /**
     * Whether objects can be requested by id even if they are not a ref tip, which v2 always allows for reachable objects
     */
    public function allowsReachableWants(): bool
    {
        $this->discover();

        return $this->version === 2 || isset($this->capabilities['allow-reachable-sha1-in-want']) || isset($this->capabilities['allow-any-sha1-in-want']);
    }

    public function listRefs(): RemoteRefs
    {
        $this->discover();

        if ($this->version !== 2) {
            return RemoteRefs::fromRefList($this->advertisedRefs, $this->objectFormat);
        }

        $args = ['peel', 'symrefs', 'ref-prefix HEAD', 'ref-prefix refs/heads/', 'ref-prefix refs/tags/'];
        $refs = [];
        foreach (PktLine::decode($this->post($this->buildV2Command('ls-refs', $args))) as $packet) {
            if ($packet instanceof PktSpecial) {
                continue;
            }
            PktLine::assertNotError($packet);
            $parts = explode(' ', rtrim($packet, "\n"));
            if (\count($parts) < 2) {
                throw new ProtocolException('Invalid ls-refs line');
            }
            $ref = ['oid' => $parts[0], 'name' => $parts[1], 'peeled' => null, 'symrefTarget' => null];
            foreach (\array_slice($parts, 2) as $attribute) {
                if (str_starts_with($attribute, 'peeled:')) {
                    $ref['peeled'] = substr($attribute, 7);
                } elseif (str_starts_with($attribute, 'symref-target:')) {
                    $ref['symrefTarget'] = substr($attribute, 14);
                }
            }
            $refs[] = $ref;
        }

        return RemoteRefs::fromRefList($refs, $this->objectFormat);
    }

    /**
     * @param non-empty-list<string> $wants object ids, which for v0 servers without allowsReachableWants() must be advertised tips
     *
     * @return string the pack file
     */
    public function fetch(array $wants, bool $shallow, ?string $filter): string
    {
        $this->discover();

        if ($filter !== null && !$this->supportsFilter()) {
            throw new \LogicException('The server does not support filters');
        }
        if ($shallow && !$this->supportsShallow()) {
            throw new \LogicException('The server does not support shallow fetches');
        }

        return $this->version === 2 ? $this->fetchV2($wants, $shallow, $filter) : $this->fetchV0($wants, $shallow, $filter);
    }

    private function discover(): void
    {
        if ($this->serviceUrl !== null) {
            return;
        }

        $discoveryUrl = rtrim($this->url, '/').self::SERVICE_SUFFIX;
        $response = $this->request($discoveryUrl, null);
        $contentType = strtolower(trim(explode(';', (string) $response->getHeader('content-type'))[0]));
        $body = (string) $response->getBody();

        if ($contentType !== 'application/x-git-upload-pack-advertisement') {
            if (Preg::isMatch('{^[0-9a-f]{40,64}\t}', $body)) {
                throw new UnsupportedRepositoryException('The server at '.$this->url.' only supports the dumb HTTP protocol, which is not supported. Enable smart HTTP on your git server.');
            }

            throw new RepositoryNotFoundException('No git repository found at '.$this->url.' (got '.($contentType ?: 'no content type').' instead of a git ref advertisement)');
        }

        // git follows redirects of the discovery request and talks to the redirected base from then on
        $effectiveUrl = $response instanceof CurlResponse ? ($response->getCurlInfo()['url'] ?? $discoveryUrl) : $discoveryUrl;
        if (!str_ends_with($effectiveUrl, self::SERVICE_SUFFIX) || !str_starts_with($effectiveUrl, 'https://')) {
            throw new RepositoryNotFoundException('The repository at '.$this->url.' redirected to an unexpected location');
        }
        $this->serviceUrl = substr($effectiveUrl, 0, -\strlen(self::SERVICE_SUFFIX)).'/git-upload-pack';

        $packets = PktLine::decode($body);
        if (\is_string($packets[0] ?? null) && str_starts_with($packets[0], '# service=')) {
            array_shift($packets);
            if (($packets[0] ?? null) === PktSpecial::Flush) {
                array_shift($packets);
            }
        }
        if (isset($packets[0]) && \is_string($packets[0])) {
            PktLine::assertNotError($packets[0]);
        }

        if (($packets[0] ?? null) === "version 2\n") {
            $this->parseV2Capabilities(\array_slice($packets, 1));
        } else {
            if (($packets[0] ?? null) === "version 1\n") {
                array_shift($packets);
            }
            $this->parseV0Advertisement($packets);
        }
    }

    /**
     * @param list<string|PktSpecial> $packets
     */
    private function parseV2Capabilities(array $packets): void
    {
        $this->version = 2;
        foreach ($packets as $packet) {
            if ($packet instanceof PktSpecial) {
                break;
            }
            [$key, $value] = array_pad(explode('=', rtrim($packet, "\n"), 2), 2, '');
            $this->capabilities[$key] = $value;
        }
        if (!isset($this->capabilities['ls-refs'], $this->capabilities['fetch'])) {
            throw new UnsupportedRepositoryException('The server at '.$this->url.' does not offer ls-refs and fetch');
        }
        $this->objectFormat = ObjectFormat::fromCapability($this->capabilities['object-format'] ?? null);
    }

    /**
     * @param list<string|PktSpecial> $packets
     */
    private function parseV0Advertisement(array $packets): void
    {
        $this->version = 0;
        $advertised = [];
        $peeled = [];
        foreach ($packets as $index => $packet) {
            if ($packet instanceof PktSpecial) {
                break;
            }
            $line = rtrim($packet, "\n");
            if ($index === 0) {
                [$line, $capabilities] = array_pad(explode("\0", $line, 2), 2, '');
                foreach (explode(' ', $capabilities) as $capability) {
                    if ($capability !== '') {
                        [$key, $value] = array_pad(explode('=', $capability, 2), 2, '');
                        // symref may appear several times, only HEAD's matters
                        if ($key === 'symref' && !str_starts_with($value, 'HEAD:')) {
                            continue;
                        }
                        $this->capabilities[$key] = $value;
                    }
                }
            }
            [$oid, $name] = array_pad(explode(' ', $line, 2), 2, '');
            if ($name === 'capabilities^{}') {
                // empty repository
                continue;
            }
            if (str_ends_with($name, '^{}')) {
                $peeled[substr($name, 0, -3)] = $oid;
            } else {
                $advertised[$name] = $oid;
            }
        }

        $this->objectFormat = ObjectFormat::fromCapability($this->capabilities['object-format'] ?? null);
        $symrefTarget = isset($this->capabilities['symref']) ? substr($this->capabilities['symref'], 5) : null;
        foreach ($advertised as $name => $oid) {
            $name = (string) $name;
            $this->advertisedRefs[] = ['oid' => $oid, 'name' => $name, 'peeled' => $peeled[$name] ?? null, 'symrefTarget' => $name === 'HEAD' ? $symrefTarget : null];
        }
    }

    /**
     * @param non-empty-list<string> $wants
     */
    private function fetchV2(array $wants, bool $shallow, ?string $filter): string
    {
        $args = ['no-progress', 'ofs-delta'];
        foreach ($wants as $want) {
            $args[] = 'want '.$want;
        }
        if ($shallow) {
            $args[] = 'deepen 1';
        }
        if ($filter !== null) {
            $args[] = 'filter '.$filter;
        }
        $args[] = 'done';

        $packets = PktLine::decode($this->post($this->buildV2Command('fetch', $args)));
        foreach ($packets as $index => $packet) {
            PktLine::assertNotError($packet);
            if ($packet === "packfile\n") {
                return $this->extractPack(\array_slice($packets, $index + 1));
            }
        }

        throw new ProtocolException('The server response contained no pack');
    }

    /**
     * @param non-empty-list<string> $wants
     */
    private function fetchV0(array $wants, bool $shallow, ?string $filter): string
    {
        if (isset($this->capabilities['side-band-64k'])) {
            $capabilities = ['side-band-64k'];
        } elseif (isset($this->capabilities['side-band'])) {
            $capabilities = ['side-band'];
        } else {
            throw new UnsupportedRepositoryException('The server at '.$this->url.' does not support side-band');
        }
        // Azure DevOps refuses clients that do not ask for multi_ack
        foreach (['multi_ack_detailed', 'ofs-delta', 'no-progress'] as $capability) {
            if (isset($this->capabilities[$capability])) {
                $capabilities[] = $capability;
            }
        }
        if ($shallow) {
            $capabilities[] = 'shallow';
        }
        if ($filter !== null) {
            $capabilities[] = 'filter';
        }
        if ($this->objectFormat !== ObjectFormat::Sha1) {
            $capabilities[] = 'object-format='.$this->objectFormat->value;
        }
        $capabilities[] = 'agent='.self::AGENT;

        $body = '';
        foreach ($wants as $index => $want) {
            $body .= PktLine::encode('want '.$want.($index === 0 ? ' '.implode(' ', $capabilities) : '')."\n");
        }
        if ($shallow) {
            $body .= PktLine::encode("deepen 1\n");
        }
        if ($filter !== null) {
            $body .= PktLine::encode('filter '.$filter."\n");
        }
        $body .= PktLine::FLUSH.PktLine::encode("done\n");

        // shallow lines and a flush come first, then NAK (we never send haves), then the side-band pack
        $packets = PktLine::decode($this->post($body));
        foreach ($packets as $index => $packet) {
            PktLine::assertNotError($packet);
            if (\is_string($packet) && (str_starts_with($packet, 'NAK') || str_starts_with($packet, 'ACK'))) {
                return $this->extractPack(\array_slice($packets, $index + 1));
            }
        }

        throw new ProtocolException('The server response contained no pack');
    }

    /**
     * @param list<string|PktSpecial> $packets
     */
    private function extractPack(array $packets): string
    {
        $pack = PktLine::demultiplex($packets);
        if (!str_starts_with($pack, 'PACK')) {
            throw new ProtocolException('The server response contained no pack');
        }

        return $pack;
    }

    /**
     * @param list<string> $args
     */
    private function buildV2Command(string $command, array $args): string
    {
        $body = PktLine::encode('command='.$command."\n").PktLine::encode('agent='.self::AGENT."\n");
        if (isset($this->capabilities['object-format'])) {
            $body .= PktLine::encode('object-format='.$this->objectFormat->value."\n");
        }
        $body .= PktLine::DELIM;
        foreach ($args as $arg) {
            $body .= PktLine::encode($arg."\n");
        }

        return $body.PktLine::FLUSH;
    }

    private function post(string $body): string
    {
        \assert($this->serviceUrl !== null);
        $response = $this->request($this->serviceUrl, $body);
        if (strtolower(trim(explode(';', (string) $response->getHeader('content-type'))[0])) !== 'application/x-git-upload-pack-result') {
            throw new ProtocolException('Unexpected content type in response from '.$this->url);
        }

        return (string) $response->getBody();
    }

    private function request(string $url, ?string $body): Response
    {
        // the user agent goes first so it replaces the default one when HttpDownloader merges header lists by index
        $headers = ['User-Agent: '.self::USER_AGENT, 'Git-Protocol: version=2'];
        $options = ['max_file_size' => $this->maxResponseSize, 'retry-auth-failure' => false];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/x-git-upload-pack-request';
            $headers[] = 'Accept: application/x-git-upload-pack-result';
            $options['http'] = ['method' => 'POST', 'content' => $body];
        }
        $options['http']['header'] = $headers;

        try {
            return $this->httpDownloader->get($url, $options);
        } catch (MaxFileSizeExceededException $e) {
            throw new LimitExceededException('The response from '.$this->url.' exceeds the limit of '.$this->maxResponseSize.' bytes', 0, $e);
        } catch (TransportException $e) {
            throw $this->convertTransportException($e, $body !== null);
        }
    }

    private function convertTransportException(TransportException $e, bool $isPost): GitException
    {
        $status = $e->getStatusCode();
        $message = 'Failed to read '.$this->url.': '.$e->getMessage();

        if ($status === null || $status < 400) {
            // blocked private IPs and insecure redirects are permanent, anything else at the network level may go away
            if (str_contains($e->getMessage(), 'is blocked for') || str_contains($e->getMessage(), 'does not allow connections to http://')) {
                return new UnsupportedRepositoryException($message, 0, $e);
            }

            return new RepositoryUnavailableException($message, 0, $e);
        }
        if ($status === 429 || $status >= 500) {
            return new RepositoryUnavailableException($message, $status, $e);
        }
        if ($isPost) {
            // e.g. Azure DevOps answers 400 to filters it does not support
            return new ServerRefusedException(trim((string) $e->getResponse()) ?: $message, $status, $e);
        }

        return new RepositoryNotFoundException($message, $status, $e);
    }

    /**
     * @return list<string>
     */
    private function getFetchFeatures(): array
    {
        return array_values(array_filter(explode(' ', $this->capabilities['fetch'] ?? '')));
    }
}
