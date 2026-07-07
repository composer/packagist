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
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Confirmation form for leaving an organization. It carries no fields, providing CSRF protection
 * and a submit target; it is the place to add a confirmation input later.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class LeaveOrganizationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
    }

    public function getBlockPrefix(): string
    {
        return 'leave_organization';
    }
}
