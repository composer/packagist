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

namespace App\ArgumentResolver;

use App\Attribute\VarName;
use App\Entity\Package;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PackageResolver implements ValueResolverInterface
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    /**
     * @return iterable<Package>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (Package::class !== $argument->getType()) {
            return [];
        }

        $varName = $argument->getName();
        if ($attrs = $argument->getAttributes(VarName::class)) {
            foreach ($attrs as $attr) {
                if ($attr instanceof VarName) {
                    $varName = $attr->name;
                }
            }
        }

        $pkgName = $request->attributes->get($varName);
        if (!is_string($pkgName)) {
            throw new \UnexpectedValueException('Missing "'.$varName.'" in request attributes, cannot resolve $'.$argument->getName());
        }

        $package = $this->doctrine->getRepository(Package::class)->findOneBy(['name' => $pkgName]);
        if (!$package) {
            throw new NotFoundHttpException('Package with name '.$pkgName.' was not found');
        }

        yield $package;
    }
}
