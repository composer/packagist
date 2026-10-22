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

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

class PackageUnfreezeSupportRequest
{
    /**
     * @var list<string>
     */
    #[Assert\Count(min: 1, minMessage: 'Pick at least one package.')]
    public array $packageNames = [];

    #[Assert\NotBlank]
    #[Assert\Length(max: 4000)]
    public string $description = '';
}
