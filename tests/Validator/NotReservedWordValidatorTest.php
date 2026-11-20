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

namespace App\Tests\Validator;

use App\Log\ActorLabel;
use App\Validator\NotReservedWord;
use App\Validator\NotReservedWordValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/** @extends ConstraintValidatorTestCase<NotReservedWordValidator> */
class NotReservedWordValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): ConstraintValidatorInterface
    {
        return new NotReservedWordValidator();
    }

    #[DataProvider('reservedNames')]
    public function testReservedNamesAreRejectedRegardlessOfCase(string $name): void
    {
        $constraint = new NotReservedWord();
        $this->validator->validate($name, $constraint);
        $this->buildViolation($constraint->message)->setParameter('{{ value }}', $name)->assertRaised();
    }

    /** @return iterable<string, array{string}> */
    public static function reservedNames(): iterable
    {
        $names = ['composer', 'packagist', 'php', 'create'];

        foreach (ActorLabel::cases() as $label) {
            $names[] = $label->value;
        }

        foreach ($names as $name) {
            yield $name => [$name];
            yield $name.' uppercase' => [strtoupper($name)];
        }
    }

    #[TestWith(['my-vendor'])]
    #[TestWith(['automation-tools'])]
    #[TestWith([null])]
    public function testOtherValuesAreAllowed(?string $name): void
    {
        $this->validator->validate($name, new NotReservedWord());
        $this->assertNoViolation();
    }
}
