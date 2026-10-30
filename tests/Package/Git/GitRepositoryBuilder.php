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

namespace App\Tests\Package\Git;

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Builds real git repositories with the git binary, isolated from the user's git config
 */
final class GitRepositoryBuilder
{
    private int $time = 1_700_000_000;

    public function __construct(
        public readonly string $workDir,
        string $objectFormat = 'sha1',
    ) {
        new Filesystem()->remove($workDir);
        new Filesystem()->mkdir($workDir);
        $this->git('init', '-q', '--object-format='.$objectFormat, '--initial-branch=main');
    }

    public static function createTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir().'/'.$prefix.'-'.bin2hex(random_bytes(6));
        new Filesystem()->mkdir($dir);

        return $dir;
    }

    /**
     * @param array<string, string|null> $files path => contents, null deletes the file
     *
     * @return string the commit id
     */
    public function commit(array $files, string $message = 'commit'): string
    {
        foreach ($files as $path => $contents) {
            $fullPath = $this->workDir.'/'.$path;
            if ($contents === null) {
                new Filesystem()->remove($fullPath);
            } else {
                new Filesystem()->dumpFile($fullPath, $contents);
            }
        }
        $this->time += 3600;
        $this->git('add', '-A');
        $this->git('commit', '-q', '--allow-empty', '-m', $message);

        return $this->revParse('HEAD');
    }

    public function tag(string $name, bool $annotated = false): void
    {
        $annotated ? $this->git('tag', '-a', '-m', $name, $name) : $this->git('tag', $name);
    }

    public function checkout(string $branch, bool $create = false): void
    {
        $create ? $this->git('checkout', '-q', '-b', $branch) : $this->git('checkout', '-q', $branch);
    }

    public function revParse(string $rev): string
    {
        return trim($this->git('rev-parse', $rev));
    }

    public function authorTime(string $rev): int
    {
        return (int) trim($this->git('log', '-1', '--format=%at', $rev));
    }

    /**
     * Publishes the repository as a bare clone named $name under $projectRoot, as served by git http-backend
     */
    public function publish(string $projectRoot, string $name): string
    {
        $target = $projectRoot.'/'.$name;
        new Filesystem()->remove($target);
        $this->git('clone', '-q', '--bare', $this->workDir, $target);

        return $target;
    }

    public function git(string ...$args): string
    {
        return self::run([...$args], $this->workDir, [
            'GIT_AUTHOR_DATE' => $this->time.' +0000',
            'GIT_COMMITTER_DATE' => $this->time.' +0000',
        ]);
    }

    /**
     * @param list<string>          $args
     * @param array<string, string> $env
     */
    public static function run(array $args, string $cwd, array $env = [], ?string $input = null): string
    {
        $process = new Process(['git', ...$args], $cwd, self::env() + $env, $input);
        $process->mustRun();

        return $process->getOutput();
    }

    /**
     * @return array<string, string>
     */
    public static function env(): array
    {
        return [
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_AUTHOR_NAME' => 'Packagist Test',
            'GIT_AUTHOR_EMAIL' => 'test@example.org',
            'GIT_COMMITTER_NAME' => 'Packagist Test',
            'GIT_COMMITTER_EMAIL' => 'test@example.org',
            'GIT_TERMINAL_PROMPT' => '0',
            // VcsRepositoryFactory restricts this process wide, fixtures are built from local paths
            'GIT_ALLOW_PROTOCOL' => 'file',
        ];
    }
}
