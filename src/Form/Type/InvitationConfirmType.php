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
 * Bare confirmation form (CSRF + submit target only) shared by the invitation lifecycle actions:
 * resend, revoke, accept and decline. Each is a single deliberate POST to its own route.
 *
 * @extends AbstractType<array<string, mixed>>
 */
class InvitationConfirmType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
    }

    public function getBlockPrefix(): string
    {
        return 'invitation_confirm';
    }
}
