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

use Composer\Pcre\Preg;

final readonly class GitObject
{
    public function __construct(
        public ObjectType $type,
        public string $contents,
    ) {
    }

    /**
     * @return array{tree: string, authorTime: ?int}
     */
    public function parseCommit(ObjectFormat $format): array
    {
        if ($this->type !== ObjectType::Commit) {
            throw new ProtocolException('Expected a commit, got a '.$this->type->label());
        }

        $headerEnd = strpos($this->contents, "\n\n");
        $headers = $headerEnd === false ? $this->contents : substr($this->contents, 0, $headerEnd + 1);

        if (!Preg::isMatchStrictGroups('{\Atree ([0-9a-f]+)\n}', $headers, $match) || !$format->isObjectId($match[1])) {
            throw new ProtocolException('Commit object has no valid tree');
        }

        $authorTime = null;
        if (Preg::isMatchStrictGroups('{^author .*> (\d+) [+-]\d{4}$}m', $headers, $authorMatch)) {
            $authorTime = (int) $authorMatch[1];
        }

        return ['tree' => $match[1], 'authorTime' => $authorTime];
    }

    /**
     * @return array<string, array{mode: string, oid: string}> entries by name
     */
    public function parseTree(ObjectFormat $format): array
    {
        if ($this->type !== ObjectType::Tree) {
            throw new ProtocolException('Expected a tree, got a '.$this->type->label());
        }

        $entries = [];
        $pos = 0;
        $length = \strlen($this->contents);
        $oidLength = $format->rawLength();
        while ($pos < $length) {
            $space = strpos($this->contents, ' ', $pos);
            $nul = strpos($this->contents, "\0", $pos);
            if ($space === false || $nul === false || $space > $nul || $nul + 1 + $oidLength > $length) {
                throw new ProtocolException('Malformed tree object');
            }

            $entries[substr($this->contents, $space + 1, $nul - $space - 1)] = [
                'mode' => substr($this->contents, $pos, $space - $pos),
                'oid' => bin2hex(substr($this->contents, $nul + 1, $oidLength)),
            ];
            $pos = $nul + 1 + $oidLength;
        }

        return $entries;
    }
}
