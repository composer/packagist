<?php

namespace App\ArgumentResolver;

use App\Attribute\VarName;
use App\Entity\User;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class UserResolver implements ArgumentValueResolverInterface
{
    public function __construct(
        private ManagerRegistry $doctrine,
    ) {
    }

    public function supports(Request $request, ArgumentMetadata $argument): bool
    {
        return User::class === $argument->getType() && \count($argument->getAttributes(CurrentUser::class)) === 0;
    }

    /**
     * @return iterable<User>
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

        $username = $request->attributes->get($varName);
        if (!is_string($username)) {
            throw new \UnexpectedValueException('Missing "'.$varName.'" in request attributes, cannot resolve $'.$argument->getName());
        }

        $user = $this->doctrine->getRepository(User::class)->findOneBy(['usernameCanonical' => $username]);
        if (!$user) {
            throw new NotFoundHttpException('User with name '.$username.' was not found');
        }

        yield $user;
    }
}
