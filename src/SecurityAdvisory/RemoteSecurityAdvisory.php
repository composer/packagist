<?php declare(strict_types=1);

namespace App\SecurityAdvisory;

use App\Entity\SecurityAdvisory;

class RemoteSecurityAdvisory
{
    /** @var string */
    private $id;
    /** @var string */
    private $title;
    /** @var string */
    private $packageName;
    /** @var string */
    private $affectedVersions;
    /** @var string */
    private $link;
    /** @var ?string */
    private $cve;
    /** @var \DateTime */
    private $date;
    /** @var string|null */
    private $composerRepository;

    public function __construct(string $id, string $title, string $packageName, string $affectedVersions, string $link, $cve, \DateTime $date, ?string $composerRepository)
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

    public static function createFromFriendsOfPhp(string $fileNameWithPath, array $info): RemoteSecurityAdvisory
    {
        $date = null;
        $fallbackYearDate = null;
        if (preg_match('#(\d{4}-\d{2}-\d{2})#', basename($fileNameWithPath), $matches)) {
            $date = new \DateTime($matches[1] . ' 00:00:00');
        } elseif (preg_match('#CVE-(2\d{3})-\d#', basename($fileNameWithPath), $matches)) {
            $fallbackYearDate = new \DateTime($matches[1] . '-01-01 00:00:00');
        }

        $affectedVersions = [];
        $lowestBranchDate = null;
        foreach ($info['branches'] as $branchInfo) {
            $affectedVersions[] = implode(',', $branchInfo['versions']);
            if (!$date && isset($branchInfo['time']) && is_int($branchInfo['time'])) {
                $branchDate = new \DateTime('@' . $branchInfo['time']);
                if (!$lowestBranchDate || $branchDate < $lowestBranchDate) {
                    $lowestBranchDate = $branchDate;
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

        return new RemoteSecurityAdvisory(
            $fileNameWithPath,
            $info['title'],
            str_replace('composer://', '', $info['reference']),
            implode('|', $affectedVersions),
            $info['link'],
            $info['cve'] ?? null,
            $date,
            $composerRepository
        );
    }
}
