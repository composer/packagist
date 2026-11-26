<?php

declare(strict_types=1);

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

use App\Entity\Tag;
use App\Tests\IntegrationTestCase;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\NoResultException;

final class TagGetByNameTest extends IntegrationTestCase
{
    public function testCreateWritesTheTagImmediately(): void
    {
        $tag = Tag::getByName($this->em(), 'fresh-tag', true);

        // no flush: the row has to exist before a concurrent update looks it up
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tag WHERE name = ?', ['fresh-tag']));
        self::assertSame('fresh-tag', $tag->getName());
        self::assertSame($tag, Tag::getByName($this->em(), 'fresh-tag', true));
    }

    public function testCreateReusesATagInsertedElsewhere(): void
    {
        $this->em()->getConnection()->executeStatement('INSERT INTO tag (name) VALUES (?)', ['other-worker']);

        $tag = Tag::getByName($this->em(), 'Other-Worker', true);

        self::assertSame('other-worker', $tag->getName());
        self::assertSame(1, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tag'));
    }

    public function testNamesEqualUnderTheCollationResolveToTheSameTag(): void
    {
        $first = Tag::getByName($this->em(), 'aşet', true);
        $second = Tag::getByName($this->em(), 'aset', true);

        self::assertSame($first->getId(), $second->getId());
        self::assertSame('aşet', $second->getName());
        $this->em()->flush();
    }

    public function testMissingTagThrowsWithoutCreate(): void
    {
        $this->expectException(NoResultException::class);

        Tag::getByName($this->em(), 'does-not-exist');
    }

    public function testMissingTagIsNotWrittenWithoutCreate(): void
    {
        try {
            Tag::getByName($this->em(), 'does-not-exist');
        } catch (NoResultException) {
        }

        self::assertSame(0, (int) $this->em()->getConnection()->fetchOne('SELECT COUNT(*) FROM tag'));
    }

    private function em(): EntityManager
    {
        $em = self::getEM();
        self::assertInstanceOf(EntityManager::class, $em);

        return $em;
    }
}
