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

namespace App\Doctrine\Type;

use App\Util\IpAddress as IpAddressConverter;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Type;

class IpAddress extends Type
{
    private const string NAME = 'ipaddress';

    public function getSQLDeclaration(array $column, AbstractPlatform $platform): string
    {
        return 'VARBINARY(16)';
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return IpAddressConverter::binaryToString($value);
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        return IpAddressConverter::stringToBinary($value);
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getBindingType(): \Doctrine\DBAL\ParameterType
    {
        return \Doctrine\DBAL\ParameterType::BINARY;
    }
}
