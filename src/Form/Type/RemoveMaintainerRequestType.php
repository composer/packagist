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

use App\Entity\Package;
use App\Entity\User;
use App\Entity\UserRepository;
use App\Form\Model\MaintainerRequest;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RemoveMaintainerRequestType extends AbstractType
{
    /**
     * @param array{package: Package, excludeUser: User} $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('user', EntityType::class, [
            'class' => User::class,
            'query_builder' => static function (UserRepository $er) use ($options) {
                return $er->getPackageMaintainersQueryBuilder($options['package'], $options['excludeUser']);
            },
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setRequired(['package']);
        $resolver->setDefaults([
            'excludeUser' => null,
            'data_class' => MaintainerRequest::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'remove_maintainer_form';
    }
}
