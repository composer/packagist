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

use App\Entity\Package;
use App\Entity\PackageRepository;
use Composer\Downloader\TransportException;
use Composer\Pcre\Preg;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class ValidPackageRepositoryValidator extends ConstraintValidator
{
    public function __construct(
        private PackageRepository $packageRepository,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidPackageRepository) {
            throw new UnexpectedTypeException($constraint, ValidPackageRepository::class);
        }

        // custom constraints should ignore null and empty values to allow
        // other constraints (NotBlank, NotNull, etc.) to take care of that
        if (null === $value || '' === $value) {
            return;
        }

        if (!$value instanceof Package) {
            throw new UnexpectedValueException($value, Package::class);
        }

        // vcs driver was not nulled which means the repository was not set/modified and is still valid
        if (true === $value->vcsDriver && '' !== $value->getName()) {
            return;
        }

        $driver = $value->vcsDriver;
        if (!is_object($driver)) {
            if (Preg::isMatch('{^http://}', $value->getRepository())) {
                $this->addViolation('Non-secure HTTP URLs are not supported, make sure you use an HTTPS or SSH URL');
            } elseif (Preg::isMatch('{https?://.+@}', $value->getRepository())) {
                $this->addViolation('URLs with user@host are not supported, use a read-only public URL');
            } elseif (is_string($value->vcsDriverError)) {
                $this->addViolation('Uncaught Exception: '.htmlentities($value->vcsDriverError, ENT_COMPAT, 'utf-8'));
            } else {
                $this->addViolation('No valid/supported repository was found at the given URL');
            }

            return;
        }

        try {
            $information = $driver->getComposerInformation($driver->getRootIdentifier());
            if (empty($information['name']) || !is_string($information['name'])) {
                $this->addViolation('The package name was not found in the composer.json, make sure there is a name present.');

                return;
            }
        } catch (\Exception $e) {
            if ($e instanceof TransportException && $e->getCode() === 404) {
                $this->addViolation('No composer.json was found in the '.$driver->getRootIdentifier().' branch.');

                return;
            }

            $this->addViolation('We had problems parsing your composer.json file, the parser reports: '.htmlentities($e->getMessage(), ENT_COMPAT, 'utf-8'));

            return;
        }

        if ('' === $value->getName()) {
            $this->addViolation('An unexpected error has made our parser fail to find a package name in your repository, if you think this is incorrect please try again');

            return;
        }

        $name = $value->getName();
        if (!Preg::isMatch('{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$}iD', $name)) {
            $this->addViolation('The package name '.htmlentities($name, ENT_COMPAT, 'utf-8').' is invalid, it should have a vendor name, a forward slash, and a package name. The vendor and package name can be words separated by -, . or _. The complete name should match "[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*".');

            return;
        }

        // TODO do not check if vendor is an approved one
        if (
            Preg::isMatch('{(free.*watch|watch.*free|(stream|online).*anschauver.*pelicula|ver.*completa|pelicula.*complet|season.*episode.*online|film.*(complet|entier)|(voir|regarder|guarda|assistir).*(film|complet)|full.*movie|online.*(free|tv|full.*hd)|(free|full|gratuit).*stream|movie.*free|free.*(movie|hack)|watch.*movie|watch.*full|generate.*resource|generate.*unlimited|hack.*coin|coin.*(hack|generat)|vbucks|hack.*cheat|hack.*generat|generat.*hack|hack.*unlimited|cheat.*(unlimited|generat)|(mod(?!ule|el)|cheat|apk).*(hack|cheat|mod(?!ule|el))|hack.*(apk|mod(?!ule|el)|free|gold|gems|diamonds|coin)|putlocker|generat.*free|coins.*generat|(download|telecharg).*album|album.*(download|telecharg)|album.*(free|gratuit)|generat.*coins|unlimited.*coins|(fortnite|pubg|apex.*legend|t[1i]k.*t[o0]k).*(free|gratuit|generat|unlimited|coins|mobile|hack|follow))}i', str_replace(['.', '-'], '', $name))
            && !Preg::isMatch('{^(hexmode|calgamo|liberty_code(_module)?|dvi|thelia|clayfreeman|watchfulli|assaneonline|awema-pl|magemodules?|simplepleb|modullo|modernmt|modina|havefnubb|lucid-modules|codecomodo|modulith|cointavia|magento-hackathon|pragmatic-modules|pmpr|moderntribe|teamneusta|modelfox|yii2-module|chrisjenkinson|jsonphpmodifier|textmod|md-asifiqbal|modoo-id|modularthink)/}', $name)
        ) {
            $this->addViolation('The package name '.htmlentities($name, ENT_COMPAT, 'utf-8').' is blocked, if you think this is a mistake please get in touch with us.');

            return;
        }

        if (
            Preg::isMatchStrictGroups('{^([^/]*(symfony)[^/]*)/}', $name, $match)
            && !$this->packageRepository->isVendorTaken($match[1])
        ) {
            $this->addViolation('The vendor name '.htmlentities($match[1], ENT_COMPAT, 'utf-8').' is blocked, if you think this is a mistake please get in touch with us.');

            return;
        }

        $reservedVendors = ['php', 'packagist'];
        $bits = explode('/', strtolower($name));
        if (in_array($bits[0], $reservedVendors, true)) {
            $this->addViolation('The vendor name '.htmlentities($bits[0], ENT_COMPAT, 'utf-8').' is reserved, please use another name or reach out to us if you have a legitimate use for it.');

            return;
        }

        $reservedNames = ['nul', 'con', 'prn', 'aux', 'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9', 'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9'];
        $bits = explode('/', strtolower($name));
        if (in_array($bits[0], $reservedNames, true) || in_array($bits[1], $reservedNames, true)) {
            $this->addViolation('The package name '.htmlentities($name, ENT_COMPAT, 'utf-8').' is reserved, package and vendor names can not match any of: '.implode(', ', $reservedNames).'.');

            return;
        }

        if (Preg::isMatch('{\.json$}', $name)) {
            $this->addViolation('The package name '.htmlentities($name, ENT_COMPAT, 'utf-8').' is invalid, package names can not end in .json, consider renaming it or perhaps using a -json suffix instead.');

            return;
        }

        if (Preg::isMatch('{[A-Z]}', $name)) {
            $suggestName = Preg::replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $name);
            $suggestName = strtolower($suggestName);

            $this->addViolation('The package name '.htmlentities($name, ENT_COMPAT, 'utf-8').' is invalid, it should not contain uppercase characters. We suggest using '.$suggestName.' instead.');

            return;
        }
    }

    private function addViolation(string $msg): void
    {
        $this->context->buildViolation($msg)
            ->atPath('repository')
            ->addViolation()
        ;
    }
}
