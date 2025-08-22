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

namespace App\Entity;

use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
#[ORM\Entity]
#[ORM\Table(name: 'tag')]
#[ORM\UniqueConstraint(name: 'tag_name_idx', columns: ['name'])]
class Tag
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private int $id;

    #[ORM\Column(length: 191)]
    #[Assert\NotBlank]
    private string $name;

    /**
     * @var Collection<int, Version>&Selectable<int, Version>
     */
    #[ORM\ManyToMany(targetEntity: Version::class, mappedBy: 'tags')]
    private Collection $versions;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    /**
     * @throws \Doctrine\ORM\NoResultException
     */
    public static function getByName(EntityManager $em, string $name, bool $create = false): self
    {
        try {
            $qb = $em->createQueryBuilder();
            $qb->select('t')
                ->from(__CLASS__, 't')
                ->where('t.name = ?1')
                ->setMaxResults(1)
                ->setParameter(1, $name);

            return $qb->getQuery()->getSingleResult();
        } catch (\Doctrine\ORM\NoResultException $e) {
            if ($create) {
                $tag = new self($name);
                $em->persist($tag);

                return $tag;
            }
            throw $e;
        }
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isDev(): bool
    {
        // see Composer\Command\RequireCommand
        return \in_array(strtolower($this->name), ['dev', 'testing', 'static analysis'], true);
    }

    public function addVersions(Version $versions): void
    {
        $this->versions[] = $versions;
    }

    /**
     * @return Collection<int, Version>&Selectable<int, Version>
     */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
