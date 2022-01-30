<?php declare(strict_types=1);

namespace App\SecurityAdvisory;

use App\Entity\SecurityAdvisory;
use Composer\Pcre\Preg;

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
    private string $id;
    private string $title;
    private string $packageName;
    private string $affectedVersions;
    private string $link;
    private ?string $cve;
    private \DateTime $date;
    private ?string $composerRepository;

    public function __construct(string $id, string $title, string $packageName, string $affectedVersions, string $link, ?string $cve, \DateTime $date, ?string $composerRepository)
    {
        $this->id = $id;
        $this->title = $title;
        $this->packageName = $packageName;
        $this->affectedVersions = $affectedVersions;
        $this->link = $link;
        $this->cve = $cve;
        $this->date = $date;
        $this->composerRepository = $composerRepository;
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

    public function getDate(): \DateTime
    {
        return $this->date;
    }

    public function getComposerRepository(): ?string
    {
        return $this->composerRepository;
    }

    /**
     * @phpstan-param FriendsOfPhpSecurityAdvisory $info
     */
    public static function createFromFriendsOfPhp(string $fileNameWithPath, array $info): RemoteSecurityAdvisory
    {
        $date = null;
        $fallbackYearDate = null;
        if (Preg::isMatch('#(\d{4}-\d{2}-\d{2})#', basename($fileNameWithPath), $matches)) {
            $date = new \DateTime($matches[1] . ' 00:00:00');
        } elseif (Preg::isMatch('#CVE-(2\d{3})-\d#', basename($fileNameWithPath), $matches)) {
            $fallbackYearDate = new \DateTime($matches[1] . '-01-01 00:00:00');
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
                    $branchDate = new \DateTime('@' . $timestamp);
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
                $date = (new \DateTime())->setTime(0, 0, 0);
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
        if (AdvisoryParser::isValidCve((string) $info['cve'])) {
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
            $composerRepository
        );
    }
}
