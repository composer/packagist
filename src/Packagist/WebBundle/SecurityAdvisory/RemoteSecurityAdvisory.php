<?php declare(strict_types=1);

namespace Packagist\WebBundle\SecurityAdvisory;

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

    public function __construct(string $id, string $title, string $packageName, string $affectedVersions, string $link, $cve)
    {
        $this->id = $id;
        $this->title = $title;
        $this->packageName = $packageName;
        $this->affectedVersions = $affectedVersions;
        $this->link = $link;
        $this->cve = $cve;
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

    public static function createFromFriendsOfPhp(string $fileNameWithPath, array $info): RemoteSecurityAdvisory
    {
        $affectedVersion = implode('|', array_map(function (array $branchInfo) {
            return implode(',', $branchInfo['versions']);
        }, $info['branches']));

        return new RemoteSecurityAdvisory(
            $fileNameWithPath,
            $info['title'],
            str_replace('composer://', '', $info['reference']),
            $affectedVersion,
            $info['link'],
            $info['cve'] ?? null
        );
    }
}
