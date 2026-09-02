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

namespace App\Tests\Entity;

use App\Entity\SecurityAdvisory;
use App\SecurityAdvisory\FriendsOfPhpSecurityAdvisoriesSource;
use App\SecurityAdvisory\GitHubSecurityAdvisoriesSource;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use App\SecurityAdvisory\Severity;
use PHPUnit\Framework\TestCase;

class SecurityAdvisoryTest extends TestCase
{
    public function testCalculateDifferenceScore(): void
    {
        $data = [
            'title' => 'Remote Code Execution',
            'link' => 'https://github.com/dedalozzo/pygmentize/issues/1',
            'cve' => null,
            'branches' => [
                '1.x' => [
                    'time' => 1494806400,
                    'versions' => ['<1.2'],
                ],
            ],
            'reference' => 'composer://3f/pygmentize',
        ];

        $remoteAdvisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('3f/pygmentize/2017-05-15.yaml', $data);
        $updatedAdvisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('3f/pygmentize/2017-05-15.yaml', $data);

        $advisory = new SecurityAdvisory($remoteAdvisory, 'source');

        $this->assertSame(0, $advisory->calculateDifferenceScore($updatedAdvisory));
    }

    public function testCalculateDifferenceScoreDoesNotMatchNullCves(): void
    {
        $data = [
            'title' => 'Remote Code Execution',
            'link' => 'https://example.org/advisory/one',
            'cve' => null,
            'branches' => [
                '1.x' => [
                    'time' => 1494806400,
                    'versions' => ['<1.2'],
                ],
            ],
            'reference' => 'composer://acme/security-package',
        ];

        $remoteAdvisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('acme/security-package/2017-05-15.yaml', $data);

        $data['title'] = 'Cross-Site Request Forgery';
        $data['link'] = 'https://example.org/advisory/two';
        $data['branches']['1.x']['versions'] = ['>=2.0', '<2.1'];
        $unrelatedRemoteAdvisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('acme/security-package/2026-08-25.yaml', $data);

        $advisory = new SecurityAdvisory($remoteAdvisory, FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME);

        $this->assertSame(5, $advisory->calculateDifferenceScore($unrelatedRemoteAdvisory));
    }

    public function testCalculateDifferenceScoreChangeNameAndCVE(): void
    {
        $remoteAdvisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('league/flysystem/2021-06-24.yaml', [
            'title' => 'TOCTOU Race Condition enabling remote code execution',
            'link' => 'https://github.com/thephpleague/flysystem/security/advisories/GHSA-9f46-5r25-5wfm',
            'cve' => null,
            'branches' => [
                '1.x' => [
                    'time' => '2021-06-23 23:56:59',
                    'versions' => ['<1.1.4'],
                ],
                '2.x' => [
                    'time' => '2021-06-24 00:07:59',
                    'versions' => ['>=2.0.0', '<2.1.1'],
                ],
            ],
            'reference' => 'composer://league/flysystem',
        ]);

        $advisory = new SecurityAdvisory($remoteAdvisory, FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME);

        $updatedRemoteAdvisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('league/flysystem/CVE-2021-32708.yaml', [
            'title' => 'TOCTOU Race Condition enabling remote code execution',
            'link' => 'https://github.com/thephpleague/flysystem/security/advisories/GHSA-9f46-5r25-5wfm',
            'cve' => 'CVE-2021-32708',
            'branches' => [
                '1.x' => [
                    'time' => '2021-06-23 23:56:59',
                    'versions' => ['<1.1.4'],
                ],
                '2.x' => [
                    'time' => '2021-06-24 00:07:59',
                    'versions' => ['>=2.0.0', '<2.1.1'],
                ],
            ],
            'reference' => 'composer://league/flysystem',
        ]);

        $this->assertSame(3, $advisory->calculateDifferenceScore($updatedRemoteAdvisory));
    }

    public function testCalculateDifferenceScoreCveXXXX(): void
    {
        $remoteAdvisory = $this->generateFriendsOfPhpRemoteAdvisory('CVE-2022-xxxx: CSRF token missing in forms', 'https://symfony.com/cve-2022-xxxx', 'CVE-2022-xxxx');

        $advisory = new SecurityAdvisory($remoteAdvisory, FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME);

        $updatedRemoteAdvisory = $this->generateFriendsOfPhpRemoteAdvisory('CVE-2022-99999999999: CSRF token missing in forms', 'https://symfony.com/cve-2022-99999999999', 'CVE-2022-99999999999');

        $this->assertSame(3, $advisory->calculateDifferenceScore($updatedRemoteAdvisory));
    }

    public function testStoreSeverity(): void
    {
        $friendsOfPhpRemoteAdvisory = $this->generateFriendsOfPhpRemoteAdvisory('CVE-2022-xxxx: CSRF token missing in forms', 'https://symfony.com/cve-2022-xxxx', 'CVE-2022-xxxx');
        $gitHubRemoteAdvisor = $this->generateGitHubAdvisory(null);
        $advisory = new SecurityAdvisory($friendsOfPhpRemoteAdvisory, $friendsOfPhpRemoteAdvisory->source);

        $this->assertNull($advisory->getSeverity(), "FriendsOfPHP doesn't provide severity information");
        $advisory->addSource($gitHubRemoteAdvisor->id, GitHubSecurityAdvisoriesSource::SOURCE_NAME, null);
        $advisory->updateAdvisory($this->generateGitHubAdvisory(Severity::HIGH));
        $this->assertSame(Severity::HIGH, $advisory->getSeverity(), 'GitHub should update the advisory severity');
        $this->assertSame(Severity::HIGH, $advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->getSeverity(), 'GitHub should update the source data');

        $advisory->updateAdvisory($this->generateGitHubAdvisory(Severity::MEDIUM));
        $this->assertSame(Severity::MEDIUM, $advisory->getSeverity(), 'GitHub should update the advisory severity');
        $this->assertSame(Severity::MEDIUM, $advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->getSeverity(), 'GitHub should update the source data');

        $advisory->updateAdvisory($friendsOfPhpRemoteAdvisory);
        $this->assertSame(Severity::MEDIUM, $advisory->getSeverity(), "FriendsOfPHP shouldn't reset the severity information");

        $advisory->updateAdvisory($this->generateGitHubAdvisory(Severity::HIGH));
        $this->assertSame(Severity::HIGH, $advisory->getSeverity(), 'GitHub should update the advisory severity');
        $this->assertSame(Severity::HIGH, $advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->getSeverity(), 'GitHub should update the source data');
    }

    public function testNewAdvisoryAndItsSourceAreActive(): void
    {
        $remote = $this->generateGitHubAdvisory(null);
        $advisory = new SecurityAdvisory($remote, GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $this->assertFalse($advisory->isWithdrawn());
        $this->assertNull($advisory->getWithdrawnAt());
        $this->assertTrue($advisory->hasActiveSources());
        $this->assertEquals($remote->date, $advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->getPublishedAt());
    }

    public function testWithdrawSourceKeepsTheAdvisoryWhileAnotherSourceListsIt(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->addSource('symfony/framework-bundle/CVE-2022-1111.yaml', FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME, null);

        $this->assertFalse($advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME), 'the advisory itself is still listed');

        $this->assertFalse($advisory->isWithdrawn());
        $this->assertTrue($advisory->hasActiveSources());
        $this->assertNotNull($advisory->getSourceRemoteId(GitHubSecurityAdvisoriesSource::SOURCE_NAME), 'the source row must be kept');
        $this->assertTrue($advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->isWithdrawn());
        $this->assertFalse($advisory->findSecurityAdvisorySource(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME)?->isWithdrawn());
    }

    public function testWithdrawSourceWithdrawsTheAdvisoryOnceNoSourceListsIt(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->addSource('symfony/framework-bundle/CVE-2022-1111.yaml', FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME, null);

        $this->assertFalse($advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME));
        $this->assertTrue($advisory->withdrawSource(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME), 'the last listing source withdraws the advisory');

        $this->assertTrue($advisory->isWithdrawn());
        $this->assertFalse($advisory->hasActiveSources());
        $this->assertCount(2, $advisory->getSources());
    }

    public function testWithdrawSourceIsIdempotent(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $this->assertTrue($advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME));
        $withdrawnAt = $advisory->getWithdrawnAt();
        $sourceWithdrawnAt = $advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->getWithdrawnAt();

        $this->assertFalse($advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME), 'nothing changes the second time');

        $this->assertSame($withdrawnAt, $advisory->getWithdrawnAt());
        $this->assertSame($sourceWithdrawnAt, $advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->getWithdrawnAt());
    }

    public function testWithdrawSourceIgnoresAnUnknownSource(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $advisory->withdrawSource(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME);

        $this->assertFalse($advisory->isWithdrawn());
        $this->assertTrue($advisory->hasActiveSources());
        $this->assertCount(1, $advisory->getSources());
    }

    public function testWithdrawSourcePromotesAnotherSourceToKeepSyncing(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->addSource('symfony/framework-bundle/CVE-2022-1111.yaml', FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME, null);
        $this->assertSame(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME, $advisory->getSource(), 'FriendsOfPHP is promoted when it is added');

        $advisory->withdrawSource(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME);

        $this->assertSame(GitHubSecurityAdvisoriesSource::SOURCE_NAME, $advisory->getSource());
        $this->assertSame('GHSA-1234-1234-1234', $advisory->getRemoteId());
        $this->assertFalse($advisory->isWithdrawn());
    }

    public function testWithdrawSourceKeepsTheMainSourceWhenNoneIsLeft(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $this->assertSame(GitHubSecurityAdvisoriesSource::SOURCE_NAME, $advisory->getSource());
        $this->assertSame('GHSA-1234-1234-1234', $advisory->getRemoteId());
        $this->assertTrue($advisory->isWithdrawn());
    }

    public function testReinstateSourceRevivesTheAdvisory(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $advisory->reinstateSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME, 'GHSA-1234-1234-1234');

        $this->assertFalse($advisory->isWithdrawn());
        $this->assertNull($advisory->getWithdrawnAt());
        $this->assertTrue($advisory->hasActiveSources());
        $this->assertNull($advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->getWithdrawnAt());
    }

    public function testReinstateSourceRevivesTheAdvisoryWhileTheOtherSourceStaysWithdrawn(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->addSource('symfony/framework-bundle/CVE-2022-1111.yaml', FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME, null);
        $advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->withdrawSource(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME);

        $advisory->reinstateSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME, 'GHSA-1234-1234-1234');

        $this->assertFalse($advisory->isWithdrawn());
        $this->assertFalse($advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->isWithdrawn());
        $this->assertTrue($advisory->findSecurityAdvisorySource(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME)?->isWithdrawn());
    }

    public function testReinstateSourceIgnoresASourceThatIsNotWithdrawn(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $advisory->reinstateSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME, 'GHSA-1234-1234-1234');
        $advisory->reinstateSource(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME, 'symfony/framework-bundle/CVE-2022-1111.yaml');

        $this->assertFalse($advisory->isWithdrawn());
        $this->assertCount(1, $advisory->getSources());
    }

    public function testSourcePublishedAtTracksTheDateTheSourceReports(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $friendsOfPhp = $this->generateFriendsOfPhpRemoteAdvisory('Advisory', 'https://example.org', 'CVE-2022-1111');
        $advisory->addSource($friendsOfPhp->id, FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME, null, $friendsOfPhp->date);

        $this->assertEquals($friendsOfPhp->date, $advisory->findSecurityAdvisorySource(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME)?->getPublishedAt());
        $this->assertNotEquals(
            $advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->getPublishedAt(),
            $advisory->findSecurityAdvisorySource(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME)?->getPublishedAt(),
            'each source keeps the date it published the advisory on'
        );
    }

    public function testAddSourceToAWithdrawnAdvisoryDoesNotReviveIt(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $advisory->addSource('symfony/framework-bundle/CVE-2022-1111.yaml', FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME, null);

        $this->assertTrue($advisory->isWithdrawn());
        $this->assertFalse($advisory->hasActiveSources(), 'a withdrawn advisory must never gain a listing source on its own');
        $this->assertTrue($advisory->findSecurityAdvisorySource(FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME)?->isWithdrawn());
    }

    public function testAddSourceDoesNotReinstateAnExistingWithdrawnSource(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $advisory->addSource('GHSA-1234-1234-1234', GitHubSecurityAdvisoriesSource::SOURCE_NAME, null);

        $this->assertCount(1, $advisory->getSources());
        $this->assertTrue($advisory->isWithdrawn());
        $this->assertTrue($advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->isWithdrawn());
    }

    public function testAddSourceUnderANewIdWithdrawsTheOldRowInsteadOfRenamingIt(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $advisory->addSource('GHSA-5678-5678-5678', GitHubSecurityAdvisoriesSource::SOURCE_NAME, null);

        $this->assertSame(['GHSA-1234-1234-1234', 'GHSA-5678-5678-5678'], $advisory->getSourceRemoteIds(GitHubSecurityAdvisoriesSource::SOURCE_NAME));
        $this->assertTrue($advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME, 'GHSA-1234-1234-1234')?->isWithdrawn());
        $this->assertFalse($advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME, 'GHSA-5678-5678-5678')?->isWithdrawn());
        $this->assertSame('GHSA-5678-5678-5678', $advisory->getSourceRemoteId(GitHubSecurityAdvisoriesSource::SOURCE_NAME), 'the id the source lists it under now');
        $this->assertFalse($advisory->isWithdrawn());
    }

    public function testUpdateAdvisoryNeverRewritesASourceRowId(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $renamed = new RemoteSecurityAdvisory('GHSA-9999-9999-9999', 'Tile', 'symfony/framework-bundle', '', 'https://github.com/advisories/GHSA-9999-9999-9999', null, new \DateTimeImmutable(), null, [], GitHubSecurityAdvisoriesSource::SOURCE_NAME, null);

        $advisory->updateAdvisory($renamed);

        $this->assertSame(['GHSA-1234-1234-1234'], $advisory->getSourceRemoteIds(GitHubSecurityAdvisoriesSource::SOURCE_NAME), 'a row id is part of its identifier and must not change');
    }

    public function testFindSecurityAdvisorySourcePrefersTheListingRow(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->addSource('GHSA-5678-5678-5678', GitHubSecurityAdvisoriesSource::SOURCE_NAME, null);

        $this->assertSame('GHSA-5678-5678-5678', $advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->getRemoteId());
        $this->assertSame('GHSA-1234-1234-1234', $advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME, 'GHSA-1234-1234-1234')?->getRemoteId());
        $this->assertNull($advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME, 'GHSA-0000-0000-0000'));

        $advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $this->assertSame('GHSA-5678-5678-5678', $advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->getRemoteId(), 'falls back to the last row once none lists it');
    }

    public function testDeferAndAssignCve(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->updateAdvisory(new RemoteSecurityAdvisory('GHSA-1234-1234-1234', 'Tile', 'symfony/framework-bundle', '', 'https://github.com/advisories/GHSA-1234-1234-1234', 'CVE-2024-0001', new \DateTimeImmutable(), null, [], GitHubSecurityAdvisoriesSource::SOURCE_NAME, null));

        $advisory->deferCve();
        $this->assertNull($advisory->getCve());

        $advisory->assignCve('CVE-2024-0001');
        $this->assertSame('CVE-2024-0001', $advisory->getCve());
    }

    public function testUpdateAdvisoryLeavesTheWithdrawalStateAlone(): void
    {
        $advisory = new SecurityAdvisory($this->generateGitHubAdvisory(null), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $advisory->withdrawSource(GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $advisory->updateAdvisory($this->generateGitHubAdvisory(null));

        $this->assertTrue($advisory->isWithdrawn());
        $this->assertTrue($advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->isWithdrawn());
    }

    private function generateGitHubAdvisory(?Severity $severity): RemoteSecurityAdvisory
    {
        return new RemoteSecurityAdvisory(
            'GHSA-1234-1234-1234',
            'Tile',
            'symfony/framework-bundle',
            '',
            'https://github.com/advisories/GHSA-1234-1234-1234',
            null,
            new \DateTimeImmutable(),
            null,
            [],
            GitHubSecurityAdvisoriesSource::SOURCE_NAME,
            $severity,
        );
    }

    private function generateFriendsOfPhpRemoteAdvisory(string $title, string $link, string $cve): RemoteSecurityAdvisory
    {
        return RemoteSecurityAdvisory::createFromFriendsOfPhp(\sprintf('symfony/framework-bundle/%s.yaml', $cve), [
            'title' => $title,
            'link' => $link,
            'cve' => $cve,
            'branches' => [
                '5.3.x' => [
                    'time' => '2022-01-29 12:00:00',
                    'versions' => ['>=5.3.14', '<=5.3.14'],
                ],
                '5.4.x' => [
                    'time' => '2022-01-29 12:00:00',
                    'versions' => ['>=5.4.3', '<=5.4.3'],
                ],
                '6.0.x' => [
                    'time' => '2022-01-29 12:00:00',
                    'versions' => ['>=6.0.3', '<=6.0.3'],
                ],
            ],
            'reference' => 'composer://symfony/framework-bundle',
        ]);
    }
}
