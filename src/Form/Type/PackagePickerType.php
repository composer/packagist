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
 * Checkbox list of package names the requester already holds, shared by the unfreeze and deletion
 * workflows.
 *
 * The caller passes only the names it is willing to accept, which makes the choice list the
 * authorization check: a hand-crafted POST naming somebody else's package fails Symfony's own choice
 * validation, so there is no second membership check to keep in sync.
 *
 * The data attributes are what js/packagePicker.js hangs the select-all and per-vendor toggles off.
 * Those toggles are injected at runtime rather than rendered here, so the shared
 * templates/support/request.html.twig shell -- which renders form_widget() wholesale -- needs no
 * per-field layout, and the form still works without JavaScript.
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
