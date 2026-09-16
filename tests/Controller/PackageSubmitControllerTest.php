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

namespace App\Tests\Controller;

use App\Entity\Package;
use App\Tests\IntegrationTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Covers the admin-only "assign to user" field on the submit form.
 *
 * All cases submit a scheme-less repository value, which makes Package::setRepository() bail out
 * before it builds a VcsRepository, so none of these tests hit the network.
 */
class PackageSubmitControllerTest extends IntegrationTestCase
{
    private const string NOT_A_URL = 'not-a-url';

    public function testMaintainerFieldIsHiddenFromRegularUsers(): void
    {
        $crawler = $this->openSubmitFormAs([]);

        self::assertCount(1, $crawler->filter('input[name="package[repository]"]'));
        self::assertCount(0, $crawler->filter('input[name="package[maintainer]"]'));
    }

    public function testMaintainerFieldIsShownToPackageAdmins(): void
    {
        $crawler = $this->openSubmitFormAs(['ROLE_EDIT_PACKAGES']);

        self::assertCount(1, $crawler->filter('input[name="package[maintainer]"]'));
    }

    public function testRegularUserCannotAssignAPackageToAnotherUser(): void
    {
        $victim = self::createUser('victim', 'victim@example.org', githubId: '2');
        $crawler = $this->openSubmitFormAs([], $victim);

        $crawler = $this->client->request('POST', '/packages/submit', ['package' => [
            'repository' => self::NOT_A_URL,
            'maintainer' => 'victim',
            '_token' => $crawler->filter('input[name="package[_token]"]')->attr('value'),
        ]]);

        $this->assertFormError('This form should not contain extra fields.', 'package', $crawler);
        self::assertNull($this->getEM()->getRepository(Package::class)->findOneBy(['vendor' => 'victim']));
    }

    public function testFetchInfoRejectsTheMaintainerFieldForRegularUsers(): void
    {
        // the check step must agree with the real submit, otherwise the two disagree about what is valid
        $victim = self::createUser('victim', 'victim@example.org', githubId: '2');
        $crawler = $this->openSubmitFormAs([], $victim);

        $this->client->request('POST', '/packages/fetch-info', ['package' => [
            'repository' => self::NOT_A_URL,
            'maintainer' => 'victim',
            '_token' => $crawler->filter('input[name="package[_token]"]')->attr('value'),
        ]]);

        $response = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertSame('error', $response['status']);
        self::assertContains('This form should not contain extra fields.', $response['reason']);
    }

    public function testAdminSubmitRejectsAnUnknownUsername(): void
    {
        $crawler = $this->submitAsAdmin('nosuchuser');

        $this->assertFormError('The given "nosuchuser" value is not a valid username.', 'package', $crawler);
    }

    public function testAdminSubmitRejectsADisabledUser(): void
    {
        $disabled = self::createUser('disabled', 'disabled@example.org', githubId: '2', enabled: false);
        $crawler = $this->submitAsAdmin('disabled', $disabled);

        $this->assertFormError('The given "disabled" value is not a valid username.', 'package', $crawler);
    }

    /**
     * @param array<string> $roles
     */
    private function openSubmitFormAs(array $roles, object ...$extraFixtures): Crawler
    {
        $user = self::createUser(roles: $roles);
        $this->store($user, ...$extraFixtures);
        $this->client->loginUser($user);

        return $this->client->request('GET', '/packages/submit');
    }

    private function submitAsAdmin(string $maintainer, object ...$extraFixtures): Crawler
    {
        $crawler = $this->openSubmitFormAs(['ROLE_EDIT_PACKAGES'], ...$extraFixtures);

        return $this->client->submit($crawler->selectButton('Submit')->form([
            'package[repository]' => self::NOT_A_URL,
            'package[maintainer]' => $maintainer,
        ]));
    }
}
