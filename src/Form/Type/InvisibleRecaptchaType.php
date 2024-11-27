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

use App\Validator\RateLimitingRecaptcha;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * InvisibleRecaptchaType enables recaptcha on the form after 3 wrong passwords are entered
 *
 * @extends AbstractType<array{}>
 */
class InvisibleRecaptchaType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'label' => false,
            'mapped' => false,
            'constraints' => new RateLimitingRecaptcha(),
            // If this is set to true, the RecaptchaHelper::increaseCounter must be called on failure (typically wrong password) to trigger the recaptcha enforcement after X attempts
            // by default (false) recaptcha will always be required
            'only_show_after_increment_trigger' => false,
        ]);

        $resolver->setAllowedTypes('only_show_after_increment_trigger', 'bool');
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ($options['constraints'] as $constraint) {
            if ($constraint instanceof RateLimitingRecaptcha) {
                $constraint->onlyShowAfterIncrementTrigger = $options['only_show_after_increment_trigger'];
            }
        }

        parent::buildForm($builder, $options);
    }

    public function buildView(FormView $view, FormInterface $form, array $options): void
    {
        $view->vars['only_show_after_increment_trigger'] = $options['only_show_after_increment_trigger'];
    }

    public function getParent(): string
    {
        return TextType::class;
    }
}
