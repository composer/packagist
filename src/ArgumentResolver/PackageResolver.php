<?php

namespace App\ArgumentResolver;

use App\Attribute\VarName;
use App\Entity\Package;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class PackageResolver implements ArgumentValueResolverInterface
{
    public function __construct(
        private ManagerRegistry $doctrine,
    ) {
    }

    public function supports(Request $request, ArgumentMetadata $argument): bool
    {
        return Package::class === $argument->getType();
    }

    /**
     * @return iterable<Package>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
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
