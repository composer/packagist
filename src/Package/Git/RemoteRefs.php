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

namespace App\Package\Git;

final readonly class RemoteRefs
{
    /**
     * @param array<int|string, string> $branches       branch name => commit id (numeric names become int keys)
     * @param array<int|string, string> $tags           tag name => commit id, annotated tags peeled
     * @param ?string                   $defaultBranch  branch HEAD points to, if it exists
     * @param array<string, string>     $advertisedTips commit id => id the server advertised for it (the tag object for annotated tags)
     */
    public function __construct(
        public array $branches,
        public array $tags,
        public ?string $defaultBranch,
        public array $advertisedTips,
    ) {
    }

    /**
     * @param list<array{oid: string, name: string, peeled: ?string, symrefTarget: ?string}> $refs
     */
    public static function fromRefList(array $refs, ObjectFormat $format): self
    {
        $branches = $tags = $advertisedTips = [];
        $headTarget = null;
        foreach ($refs as $ref) {
            if ($ref['name'] === 'HEAD') {
                $headTarget = $ref['symrefTarget'];
                continue;
            }
            if (!$format->isObjectId($ref['oid']) || ($ref['peeled'] !== null && !$format->isObjectId($ref['peeled']))) {
                throw new ProtocolException('Invalid object id advertised for '.$ref['name']);
            }

            $commit = $ref['peeled'] ?? $ref['oid'];
            if (str_starts_with($ref['name'], 'refs/heads/')) {
                $branches[substr($ref['name'], 11)] = $commit;
            } elseif (str_starts_with($ref['name'], 'refs/tags/')) {
                $tags[substr($ref['name'], 10)] = $commit;
            } else {
                continue;
            }
            $advertisedTips[$commit] ??= $ref['oid'];
        }

        $defaultBranch = null;
        if ($headTarget !== null && str_starts_with($headTarget, 'refs/heads/') && isset($branches[substr($headTarget, 11)])) {
            $defaultBranch = substr($headTarget, 11);
        }

        return new self($branches, $tags, $defaultBranch, $advertisedTips);
    }
}
