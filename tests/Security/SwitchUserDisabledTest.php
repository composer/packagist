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

namespace App\Tests\Security;

use App\Tests\IntegrationTestCase;

class SwitchUserDisabledTest extends IntegrationTestCase
{
    public function testSwitchUserParameterIsIgnoredEvenForSuperadmins(): void
    {
        $admin = self::createUser('admin', 'admin@example.com', roles: ['ROLE_SUPERADMIN']);
        $victim = self::createUser('victim', 'victim@example.org');
        $this->store($admin, $victim);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/profile/?_switch_user=victim');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('admin', $crawler->filter('header .username-link')->text());

        $crawler = $this->client->request('GET', '/profile/');
        self::assertSame('admin', $crawler->filter('header .username-link')->text());
    }
}
