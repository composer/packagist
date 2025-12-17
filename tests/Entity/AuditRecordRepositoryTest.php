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

use App\Audit\UserRegistrationMethod;
use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use App\Entity\User;
use App\Tests\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditRecordRepositoryTest extends IntegrationTestCase
{
    public function testInsertEnrichesRecord(): void
    {
        $em = $this->getEM();
        $requestStack = static::getContainer()->get(RequestStack::class);
        $requestStack->push(new Request(server: [
            'REMOTE_ADDR' => 'fd12:3456:789a:1:1234:5678:9abc:def0',
        ]));

        $user = self::createUser();
        self::store($user);

        $record = AuditRecord::userCreated($user, UserRegistrationMethod::REGISTRATION_FORM);

        $em->getRepository(AuditRecord::class)->insert($record);
        $em->clear();

        $insertedRecord = $em->getRepository(AuditRecord::class)->find($record->id);

        $this->assertNotNull($insertedRecord);
        $this->assertSame('fd12:3456:789a:1:1234:5678:9abc:def0', $insertedRecord->ip);
    }
}
