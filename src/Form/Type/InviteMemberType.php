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

use App\Form\Model\InviteMemberRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Invite an email address to one or more teams. The available teams are passed in as the `teams` option
 * (label => team-id string), so the caller controls which teams can be invited to (owners and custom
 * teams, never the automatically-managed all-members team).
 *
 * @extends AbstractType<InviteMemberRequest>
 */
class InviteMemberType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email address',
                'help' => 'The invitee must accept using a Packagist account with this exact email address.',
            ])
            ->add('teamIds', ChoiceType::class, [
                'label' => 'Teams',
                'choices' => $options['teams'],
                'multiple' => true,
                'expanded' => true,
                'help' => 'Adding the invitee to the Owners team requires them to have two-factor authentication enabled.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => InviteMemberRequest::class,
            'teams' => [],
        ]);
        $resolver->setAllowedTypes('teams', 'array');
    }
}
