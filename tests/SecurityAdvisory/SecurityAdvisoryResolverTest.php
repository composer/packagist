<?php declare(strict_types=1);

namespace App\Tests\SecurityAdvisory;

use App\Entity\SecurityAdvisory;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use App\SecurityAdvisory\RemoteSecurityAdvisoryCollection;
use App\SecurityAdvisory\SecurityAdvisoryResolver;
use PHPUnit\Framework\TestCase;

class SecurityAdvisoryResolverTest extends TestCase
{
    private SecurityAdvisoryResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new SecurityAdvisoryResolver();
    }

    public function testResolveAddNewAdvisory(): void
    {
        [$new, $removed] = $this->resolver->resolve([], new RemoteSecurityAdvisoryCollection([$this->createRemoteAdvisory('test')]), 'test');

        $this->assertSame([], $removed);
        $this->assertCount(1, $new);
    }

    public function testResolveRemoveOldAdvisory(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test'), 'test');
        [$new, $removed] = $this->resolver->resolve([$advisory], new RemoteSecurityAdvisoryCollection([]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([$advisory], $removed);
    }

    public function testResolveDontRemoveAdvisoryFromOtherSource(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('other'), 'other');
        [$new, $removed] = $this->resolver->resolve([$advisory], new RemoteSecurityAdvisoryCollection([]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $removed);

        $this->assertTrue($advisory->hasSources());
    }

    public function testResolveDontRemoveAdvisoryWithMultipleSources(): void
    {
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('test'), 'test');
        $advisory->addSource('other-id', 'other');
        [$new, $removed] = $this->resolver->resolve([$advisory], new RemoteSecurityAdvisoryCollection([]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $removed);

        $this->assertTrue($advisory->hasSources());
    }

    public function testResolveAddSourceToMatchingAdvisory(): void
    {
        $remoteAdvisory = $this->createRemoteAdvisory('test');
        $advisory = new SecurityAdvisory($this->createRemoteAdvisory('other'), 'other');
        [$new, $removed] = $this->resolver->resolve([$advisory], new RemoteSecurityAdvisoryCollection([$remoteAdvisory]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $removed);

        $this->assertNotNull($advisory->getSourceRemoteId('test'));
        $this->assertNotNull($advisory->getSourceRemoteId('other'));
    }

    public function testResolveEmpty(): void
    {
        [$new, $removed] = $this->resolver->resolve([], new RemoteSecurityAdvisoryCollection([]), 'test');

        $this->assertSame([], $new);
        $this->assertSame([], $removed);
    }

    private function createRemoteAdvisory(string $source): RemoteSecurityAdvisory
    {
        return new RemoteSecurityAdvisory('', 'Security Advisory', 'acme/package', '^1.0', 'https://example.org', null, new \DateTimeImmutable(), null, [], $source);
    }
}
