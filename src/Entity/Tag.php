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
use Doctrine\ORM\NoResultException;
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
     * @throws NoResultException
     */
    public static function getByName(EntityManager $em, string $name, bool $create = false): self
    {
        try {
            return self::findByName($em, $name);
        } catch (NoResultException $e) {
            if (!$create) {
                throw $e;
            }
        }

        // Insert right away instead of persisting: packages sharing a repository update in parallel, and
        // keywords equal only by collation ('aşet'/'aset') would otherwise both be persisted and clash on flush.
        $em->getConnection()->executeStatement(
            'INSERT INTO tag (name) VALUES (:name) ON DUPLICATE KEY UPDATE id = id',
            ['name' => $name],
        );

        return self::findByName($em, $name);
    }

    /**
     * @throws NoResultException
     */
    private static function findByName(EntityManager $em, string $name): self
    {
        return $em->createQueryBuilder()
            ->select('t')
            ->from(self::class, 't')
            ->where('t.name = ?1')
            ->setMaxResults(1)
            ->setParameter(1, $name)
            ->getQuery()
            ->getSingleResult();
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
