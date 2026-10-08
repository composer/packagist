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

class PackageDeletionSupportRequest
{
    /** What an admin can review row by row; beyond that, account deletion is the answer. */
    public const int MAX_PACKAGES = 50;

    /**
     * @var list<string>
     */
    #[Assert\Count(
        min: 1,
        max: self::MAX_PACKAGES,
        minMessage: 'Pick at least one package.',
        maxMessage: 'Please pick at most {{ limit }} packages. To remove your whole account and everything under it, request account deletion instead.',
    )]
    public array $packageNames = [];

    #[Assert\NotBlank]
    #[Assert\Length(max: 4000)]
    public string $description = '';

    #[Assert\IsTrue(message: 'Please confirm you understand that deletion cannot be undone.')]
    public bool $acknowledged = false;
}
