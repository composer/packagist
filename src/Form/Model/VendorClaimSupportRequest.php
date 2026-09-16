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

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

class VendorClaimSupportRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 191)]
    // Mirrors the vendor half of Package::PACKAGE_NAME_REGEX, case included: VendorWritableValidator
    // deep-links here with the real vendor, and a lowercase-only rule would reject names like
    // "PHPMailer" on the prefilled form.
    #[Assert\Regex(
        pattern: '{^[a-zA-Z0-9]++(?:[_.-]?[a-zA-Z0-9]++)*+$}',
        message: 'That is not a valid vendor name. Vendor names contain only letters, digits, and single _ . or - separators.',
    )]
    public string $vendorName = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 4000)]
    public string $description = '';
}
