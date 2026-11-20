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

namespace App\Form;

use App\Entity\User;
use App\Form\Type\InvisibleRecaptchaType;
use App\Security\SecondFactorManager;
use App\Security\TwoFactorAuthRateLimiter;
use App\Validator\NotProhibitedPassword;
use App\Validator\Password;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * @extends AbstractType<User>
 */
class ResetPasswordFormType extends AbstractType
{
    public function __construct(
        private TwoFactorAuthRateLimiter $limiter,
        private SecondFactorManager $secondFactors,
        private TotpCodeFormValidator $codeValidator,
    ) {
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'constraints' => [new NotProhibitedPassword()],
        ]);
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plainPassword', PasswordType::class, [
                // instead of being set onto the object directly,
                // this is read and encoded in the controller
                'label' => 'New password',
                'mapped' => false,
                'constraints' => [
                    new Password(),
                ],
            ])
        ;

        $user = $options['data'];

        if ($user->isTotpAuthenticationEnabled()) {
            $builder
                ->add('twoFactorCode', TextType::class, [
                    'label' => 'Two-Factor Code',
                    'required' => true,
                    'mapped' => false,
                ]);
            $builder->add('captcha', InvisibleRecaptchaType::class, ['only_show_after_increment_trigger' => true]);
            $builder->addEventListener(FormEvents::PRE_SUBMIT, fn () => $this->ensureResetAttemptAllowed($user));
            // Check the code after form validation and before the CAPTCHA failure counter observes TOTP violations
            $builder->addEventListener(FormEvents::POST_SUBMIT, fn (FormEvent $event) => $this->validateTotp($user, $event), -1);
        }
    }

    private function validateTotp(User $user, FormEvent $event): void
    {
        $form = $event->getForm();

        if (!$form->isValid()) {
            return;
        }

        $field = $form->get('twoFactorCode');
        $this->secondFactors->withAccountLock($user, function () use ($user, $field): void {
            // Parallel reset requests may have passed the initial check with the same attempt remaining
            $this->ensureResetAttemptAllowed($user);

            if (!$this->codeValidator->validateCode($field, $user)) {
                $this->limiter->recordFailure($user->getUserIdentifier());
            }
        });
    }

    private function ensureResetAttemptAllowed(User $user): void
    {
        try {
            $this->limiter->ensureNotRateLimited($user->getUserIdentifier());
        } catch (CustomUserMessageAuthenticationException $e) {
            throw new TooManyRequestsHttpException(TwoFactorAuthRateLimiter::RATE_LIMIT_DURATION * 60, $e->getMessage());
        }
    }
}
