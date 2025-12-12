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

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Exception\TransformationFailedException;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<User>
 */
class MaintainerType extends AbstractType
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addModelTransformer(new CallbackTransformer(
            function (?User $user): string {
                return $user?->getUsername() ?? '';
            },
            function (?string $username): ?User {
                if (!$username) {
                    return null;
                }

                $username = mb_strtolower($username);
                $users = $this->em->getRepository(User::class)->findEnabledUsersByUsername([$username]);

                if (!count($users) || !array_key_exists($username, $users)) {
                    $failure = new TransformationFailedException(sprintf('User "%s" does not exist.', $username));
                    $failure->setInvalidMessage('The given "{{ value }}" value is not a valid username.', [
                        '{{ value }}' => $username,
                    ]);

                    throw $failure;
                }

                return $users[$username];
            }
        ));
    }

    public function getParent(): string
    {
        return TextType::class;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'attr' => [
                'placeholder' => 'Username or email',
            ],
        ]);
    }
}
