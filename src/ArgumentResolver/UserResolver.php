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
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * Automatically loads users using the usernameCanonical property for perf reasons to avoid having to map it everywhere
 */
class UserResolver implements ValueResolverInterface
{
    public function __construct(private readonly ManagerRegistry $doctrine)
    {
    }

    /**
     * @return iterable<User>
     */
    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        if (User::class !== $argument->getType() || \count($argument->getAttributes(CurrentUser::class, ArgumentMetadata::IS_INSTANCEOF)) > 0) {
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

        $username = $request->attributes->get($varName);
        if (!\is_string($username)) {
            throw new \UnexpectedValueException('Missing "'.$varName.'" in request attributes, cannot resolve $'.$argument->getName());
        }

        $user = $this->doctrine->getRepository(User::class)->findOneBy(['usernameCanonical' => $username]);
        if (!$user) {
            throw new NotFoundHttpException('User with name '.$username.' was not found');
        }

        return [$user];
    }
}
