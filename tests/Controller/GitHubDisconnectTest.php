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

use App\Entity\User;
use App\Tests\IntegrationTestCase;

class GitHubDisconnectTest extends IntegrationTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = self::createUser('alice', 'alice@example.org');
        // no token, so the settings page does not look the username up on GitHub
        $this->user->setGithubId('12345');
        $this->store($this->user);
        $this->client->loginUser($this->user);
    }

    public function testDisconnectFromSettingsPage(): void
    {
        $crawler = $this->client->request('GET', '/profile/edit');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[href*="/oauth/github/disconnect"]'));

        $this->client->submit($crawler->filter('#github-disconnect')->form());
        self::assertResponseRedirects('/profile/edit');
        self::assertNull($this->reloadUser()->getGithubId());
    }

    public function testDisconnectRejectsGet(): void
    {
        $this->client->request('GET', '/oauth/github/disconnect', ['token' => 'irrelevant']);
        self::assertResponseStatusCodeSame(405);
        self::assertSame('12345', $this->reloadUser()->getGithubId());
    }

    public function testDisconnectRejectsInvalidToken(): void
    {
        $this->client->request('POST', '/oauth/github/disconnect', ['token' => 'invalid']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('12345', $this->reloadUser()->getGithubId());
    }

    private function reloadUser(): User
    {
        self::getEM()->clear();
        $user = self::getEM()->find(User::class, $this->user->getId());
        self::assertNotNull($user);

        return $user;
    }
}
