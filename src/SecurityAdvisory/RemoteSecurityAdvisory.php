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

namespace App\SecurityAdvisory;

use App\Entity\SecurityAdvisory;
use Composer\Pcre\Preg;
use DateTimeImmutable;

/**
 * @phpstan-type FriendsOfPhpSecurityAdvisory array{
 *     title: string,
 *     link: string,
 *     reference: string,
 *     branches: array<array{
 *        versions: string[],
 *        time?: int|string
 *     }>,
 *     cve?: string|null,
 *     composer-repository?: false|string
 * }
 */
class RemoteSecurityAdvisory
{
    /**
     * @param list<string> $references
     */
    public function __construct(
        private string $id,
        private string $title,
        private string $packageName,
        private string $affectedVersions,
        private string $link,
        private ?string $cve,
        private DateTimeImmutable $date,
        private ?string $composerRepository,
        private array $references,
        private string $source,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getPackageName(): string
    {
        return $this->packageName;
    }

    public function getAffectedVersions(): string
    {
        return $this->affectedVersions;
    }

    public function getLink(): string
    {
        return $this->link;
    }

    public function getCve(): ?string
    {
        return $this->cve;
    }

    public function getDate(): DateTimeImmutable
    {
        return $this->date;
    }

    public function getComposerRepository(): ?string
    {
        return $this->composerRepository;
    }

    /**
     * @return list<string>
     */
    public function getReferences(): array
    {
        return $this->references;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function withAddedAffectedVersion(string $version): self
    {
        return new self(
            $this->getId(),
            $this->getTitle(),
            $this->getPackageName(),
            implode('|', [$this->getAffectedVersions(), $version]),
            $this->getLink(),
            $this->getCve(),
            $this->getDate(),
            $this->getComposerRepository(),
            $this->getReferences(),
            $this->getSource(),
        );
    }

    /**
     * @phpstan-param FriendsOfPhpSecurityAdvisory $info
     */
    public static function createFromFriendsOfPhp(string $fileNameWithPath, array $info): RemoteSecurityAdvisory
    {
        $date = null;
        $fallbackYearDate = null;
        if (Preg::isMatch('#(\d{4}-\d{2}-\d{2})#', basename($fileNameWithPath), $matches)) {
            $date = new DateTimeImmutable($matches[1] . ' 00:00:00');
        } elseif (Preg::isMatch('#CVE-(2\d{3})-\d#', basename($fileNameWithPath), $matches)) {
            $fallbackYearDate = new DateTimeImmutable($matches[1] . '-01-01 00:00:00');
        }

        $affectedVersions = [];
        $lowestBranchDate = null;
        foreach ($info['branches'] as $branchInfo) {
            $affectedVersions[] = implode(',', $branchInfo['versions']);
            if (!$date && isset($branchInfo['time'])) {
                $timestamp = null;
                if (is_int($branchInfo['time'])) {
                    $timestamp = $branchInfo['time'];
                } elseif (is_string($branchInfo['time'])) {
                    $timestamp = strtotime($branchInfo['time']);
                }

                if ($timestamp) {
                    $branchDate = new DateTimeImmutable('@' . $timestamp);
                    if (!$lowestBranchDate || $branchDate < $lowestBranchDate) {
                        $lowestBranchDate = $branchDate;
                    }
                }
            }
        }

        if (!$date) {
            if ($lowestBranchDate) {
                $date = $lowestBranchDate;
            } elseif ($fallbackYearDate) {
                $date = $fallbackYearDate;
            } else {
                $date = (new \DateTimeImmutable())->setTime(0, 0, 0);
            }
        }

        // If the value is not set then the default value is https://packagist.org
        $composerRepository = SecurityAdvisory::PACKAGIST_ORG;
        if (isset($info['composer-repository'])) {
            if ($info['composer-repository'] === false) {
                $composerRepository = null;
            } else {
                $composerRepository = $info['composer-repository'];
            }
        }

        $cve = null;
        if (isset($info['cve']) && AdvisoryParser::isValidCve((string) $info['cve'])) {
            $cve = $info['cve'];
        }

        return new RemoteSecurityAdvisory(
            $fileNameWithPath,
            $info['title'],
            str_replace('composer://', '', $info['reference']),
            implode('|', $affectedVersions),
            $info['link'],
            $cve,
            $date,
            $composerRepository,
            [],
            FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME
        );
    }
}
