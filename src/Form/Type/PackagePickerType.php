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

namespace App\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The choice list is the authorization check: the caller passes only names the requester may pick,
 * so a POST naming somebody else's package fails choice validation.
 *
 * @extends AbstractType<mixed>
 */
class PackagePickerType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'expanded' => true,
            'multiple' => true,
            'group_by' => static fn (string $name): string => self::vendorOf($name),
            'choice_attr' => static fn (string $name): array => [
                'data-bulk-select' => 'true',
                'data-bulk-select-group' => self::vendorOf($name),
            ],
        ]);
    }

    public function getParent(): string
    {
        return ChoiceType::class;
    }

    private static function vendorOf(string $name): string
    {
        return explode('/', $name)[0];
    }
}
