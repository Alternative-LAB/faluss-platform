<?php

declare(strict_types=1);

namespace Faluss\Platform\Release;

final class ReleasePreparer
{
    /** @param list<array{sha: string, subject: string}> $commits */
    public static function nextVersion(string $current, string $requestedBump, array $commits): string
    {
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/D', $current, $matches) !== 1) {
            throw new \InvalidArgumentException('Current plugin version is not semantic.');
        }

        $bump = $requestedBump === 'auto' ? self::detectBump($commits) : $requestedBump;
        if (!in_array($bump, ['major', 'minor', 'patch'], true)) {
            throw new \InvalidArgumentException('Release bump must be auto, major, minor or patch.');
        }

        [$major, $minor, $patch] = array_map('intval', array_slice($matches, 1));

        return match ($bump) {
            'major' => sprintf('%d.0.0', $major + 1),
            'minor' => sprintf('%d.%d.0', $major, $minor + 1),
            default => sprintf('%d.%d.%d', $major, $minor, $patch + 1),
        };
    }

    /** @return list<array{sha: string, subject: string}> */
    public static function readCommits(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Unable to read the release commit list.');
        }

        $commits = [];
        foreach ($lines as $line) {
            [$sha, $subject] = array_pad(explode("\t", $line, 2), 2, '');
            $sha = trim($sha);
            $subject = trim($subject);
            if ($sha === '' || $subject === '' || str_starts_with($subject, 'Merge pull request ')) {
                continue;
            }
            $commits[] = ['sha' => $sha, 'subject' => $subject];
        }

        if ($commits === []) {
            throw new \RuntimeException('No release commit was found after the latest tag.');
        }

        return $commits;
    }

    /** @param list<array{sha: string, subject: string}> $commits */
    public static function releaseNotes(array $commits): string
    {
        $groups = ['Ajouté' => [], 'Corrigé' => [], 'Sécurité' => [], 'Documentation' => [], 'Modifié' => []];

        foreach ($commits as $commit) {
            $subject = $commit['subject'];
            $group = match (true) {
                str_starts_with($subject, '✨'), str_starts_with($subject, '🎉'), str_starts_with($subject, '🚀') => 'Ajouté',
                str_starts_with($subject, '🐛'), str_starts_with($subject, '🚑') => 'Corrigé',
                str_starts_with($subject, '🔒') => 'Sécurité',
                str_starts_with($subject, '📝') => 'Documentation',
                default => 'Modifié',
            };
            $label = trim((string) preg_replace('/^[^\p{L}\p{N}]+/u', '', $subject));
            $groups[$group][] = sprintf('- %s (`%s`)', $label, substr($commit['sha'], 0, 7));
        }

        $notes = '';
        foreach ($groups as $heading => $entries) {
            if ($entries === []) {
                continue;
            }
            $notes .= sprintf("### %s\n\n%s\n\n", $heading, implode("\n", $entries));
        }

        return rtrim($notes) . "\n";
    }

    /** @param list<array{sha: string, subject: string}> $commits */
    public static function prepare(
        string $root,
        string $requestedBump,
        string $date,
        array $commits
    ): string {
        $bootstrapPath = $root . '/faluss-platform.php';
        $changelogPath = $root . '/CHANGELOG.md';
        $bootstrap = self::read($bootstrapPath);
        $changelog = self::read($changelogPath);

        if (preg_match('/^ \* Version: (\d+\.\d+\.\d+)$/m', $bootstrap, $matches) !== 1) {
            throw new \RuntimeException('Plugin version header is missing.');
        }

        $current = $matches[1];
        $version = self::nextVersion($current, $requestedBump, $commits);
        $bootstrap = preg_replace('/^ \* Version: \d+\.\d+\.\d+$/m', ' * Version: ' . $version, $bootstrap, 1);
        $bootstrap = preg_replace(
            "/define\('FALUSS_PLATFORM_VERSION', '\d+\.\d+\.\d+'\);/",
            "define('FALUSS_PLATFORM_VERSION', '" . $version . "');",
            (string) $bootstrap,
            1,
            $constantReplacements
        );
        if ($constantReplacements !== 1) {
            throw new \RuntimeException('FALUSS_PLATFORM_VERSION is missing.');
        }

        $marker = "## Unreleased\n";
        $position = strpos($changelog, $marker);
        if ($position === false) {
            throw new \RuntimeException('Unreleased changelog section is missing.');
        }
        $releaseStart = strpos($changelog, "\n## [", $position + strlen($marker));
        if ($releaseStart === false) {
            throw new \RuntimeException('The changelog has no previous version section.');
        }

        $prefix = substr($changelog, 0, $position + strlen($marker));
        $history = ltrim(substr($changelog, $releaseStart));
        $release = sprintf(
            "\n## [%s] - %s\n\n%s\n",
            $version,
            $date,
            self::releaseNotes($commits)
        );

        self::write($bootstrapPath, (string) $bootstrap);
        self::write($changelogPath, $prefix . $release . $history);

        return $version;
    }

    /** @param list<array{sha: string, subject: string}> $commits */
    private static function detectBump(array $commits): string
    {
        foreach ($commits as $commit) {
            if (str_starts_with($commit['subject'], '💥') || str_contains($commit['subject'], '!:')) {
                return 'major';
            }
        }
        foreach ($commits as $commit) {
            if (str_starts_with($commit['subject'], '✨')
                || str_starts_with($commit['subject'], '🚀')
                || preg_match('/^feat(?:\(.+\))?:/i', $commit['subject']) === 1
            ) {
                return 'minor';
            }
        }

        return 'patch';
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read ' . $path);
        }

        return $contents;
    }

    private static function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('Unable to write ' . $path);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $options = getopt('', ['root:', 'commits:', 'bump:', 'date:']);
    foreach (['root', 'commits', 'bump', 'date'] as $required) {
        if (!isset($options[$required]) || !is_string($options[$required])) {
            fwrite(STDERR, 'Missing --' . $required . PHP_EOL);
            exit(2);
        }
    }

    try {
        $commits = ReleasePreparer::readCommits($options['commits']);
        echo ReleasePreparer::prepare(
            rtrim($options['root'], '/'),
            $options['bump'],
            $options['date'],
            $commits
        ) . PHP_EOL;
    } catch (\Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}
