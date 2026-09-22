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

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_CLASS)]
class PopularPackageSafety extends Constraint
{
    // Plain text on purpose. Three consumers render this with three different escaping rules -- the
    // edit page escapes it, js/submitPackage.js injects it as HTML, and api_edit_package returns it
    // as a JSON string -- so markup cannot be correct in all of them. The link to the support
    // workflow is rendered by templates/package/edit.html.twig instead, driven by editAction().
    public string $message = 'This package is very popular, so URL editing is disabled for security reasons: repointing a widely used package is how one gets hijacked. Please add a note on the old repo pointing at the new one if you can, then ask us to make the change.';

    /** Shown instead when the popularity check could not run, so the two cases are told apart. */
    public string $unknownMessage = 'We could not check how popular this package is right now, so URL editing is blocked as a precaution. Please try again in a few minutes.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
