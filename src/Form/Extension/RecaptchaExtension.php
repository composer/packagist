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

namespace App\Form\Extension;

use App\Form\EventSubscriber\FormBruteForceSubscriber;
use App\Form\EventSubscriber\FormInvalidPasswordSubscriber;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormBuilderInterface;

class RecaptchaExtension extends AbstractTypeExtension
{
    public function __construct(
        private readonly FormInvalidPasswordSubscriber $formInvalidPasswordSubscriber,
        private readonly FormBruteForceSubscriber $formBruteForceSubscriber,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventSubscriber($this->formInvalidPasswordSubscriber);
        $builder->addEventSubscriber($this->formBruteForceSubscriber);
    }

    public static function getExtendedTypes(): iterable
    {
        return [FormType::class];
    }
}
