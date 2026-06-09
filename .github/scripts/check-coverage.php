<?php
declare(strict_types=1);

/**
 * CI gate for PR patch test coverage.
 *
 * Gates solely on patch coverage — the share of newly added lines that are tested.
 * Exits 1 when patch coverage is below <minPatchCoverage>, 0 otherwise.
 * Overall PR and master figures are also computed
 * but only for the report comment — they never affect the exit code.
 *
 * 
 * Why only the PR's new lines, not overall coverage?
 *   - It is the author's responsibility: the gate only blocks on code this PR added,
 *     which the author can act on, instead of failing them for pre-existing untested
 *     code they never touched.
 *   - It avoids flaky, un-actionable failures from global-percentage drift (denominator
 *     shifts, generated code, unrelated files) that have nothing to do with the current PR change.
 *   - It steadily raises coverage: every new line must clear the bar, so the codebase
 *     improves over time without demanding a big-bang backfill of legacy tests.
 *
 * 
 * Usage: php check-coverage.php <minPatchCoverage> <prClover> <masterClover> <baseBranch>
 * Env:   PR_NUMBER, GITHUB_REPOSITORY (required by postPrComment for the gh API calls).
 */



$minPatchCoverage = (float) ($argv[1] ?? throw new \Exception("[arg 1] Minimum PR coverage not provided."));    // percentage 1..100
$prCloverFile       = $argv[2] ?? throw new \Exception("[arg 2] clover.xml path not provided.");                // Current PR clover.xml relative file path
$masterCloverFile = $argv[3] ?? throw new \Exception("[arg 3] master clover.xml path not provided.");           // master branch clover.xml relative file path
$baseBranch       = $argv[4] ?? throw new \Exception("[arg 4] base branch not provided.");                      // usualy origin/master



/**
 * Build per-line coverage from a clover report.
 *
 * Only `stmt` lines are tracked (method/cond entries are ignored) so the result
 * lines up with the executable lines git reports as added. File names are clover's
 * absolute paths.
 *
 * @return array<string, array<int, bool>> ['/abs/file.php' => [lineNum => isCovered]]
 */
function parseClover(string $path): array
{
    $xml      = simplexml_load_file($path);
    $coverage = [];

    foreach ($xml->project->file as $file) {
        $lines = [];
        foreach ($file->line as $line) {
            if ((string)$line['type'] === 'stmt') {
                $lines[(int)$line['num']] = (int)$line['count'] > 0;
            }
        }
        $coverage[(string)$file['name']] = $lines;
    }

    return $coverage;
}

/**
 * Read the aggregate <metrics> element from a clover report.
 *
 * @return array{line: float, method: float} coverage percentages (0.0 when no statements/methods)
 */
function getOverallCoverage(string $cloverFile): array
{
    $xml     = simplexml_load_file($cloverFile);
    $metrics = $xml->project->metrics;

    $totalStmts     = (int)$metrics['statements'];
    $coveredStmts   = (int)$metrics['coveredstatements'];
    $totalMethods   = (int)$metrics['methods'];
    $coveredMethods = (int)$metrics['coveredmethods'];

    return [
        'line'   => $totalStmts > 0 ? ($coveredStmts / $totalStmts) * 100 : 0.0,
        'method' => $totalMethods > 0 ? ($coveredMethods / $totalMethods) * 100 : 0.0,
    ];
}

/**
 * Collect the line numbers added by this PR, per file.
 *
 * @return array<string, int[]> ['repo/relative/path.php' => [lineNum, ...]]
 */
function getAddedLines(string $baseBranch): array
{
    // `--unified=0` drops context lines so every `+` is a genuine addition;
    // `--diff-filter=ACM` limits to added/copied/modified files.
    exec(
        sprintf('git diff %s...HEAD --unified=0 --diff-filter=ACM -- "*.php" 2>&1', escapeshellarg($baseBranch)),
        $output,
        $exitCode
    );

    // Walk the diff as a small state machine.
    $addedLines  = [];
    $currentFile = null;
    $currentLine = 0;

    foreach ($output as $line) {
        if (str_starts_with($line, '+++ b/')) {
            // `+++ b/` sets the current file.
            $currentFile              = substr($line, 6);
            $addedLines[$currentFile] ??= [];
        } elseif (str_starts_with($line, '@@ ')) {
            // `@@` resets the line counter to the hunk's new-side start.
            preg_match('/@@ -\S+ \+(\d+)/', $line, $m);
            $currentLine = (int)$m[1];
        } elseif ($currentFile !== null && str_starts_with($line, '+')) {
            // Only `+` lines are recorded as added.
            $addedLines[$currentFile][] = $currentLine++;
        } elseif ($currentFile !== null && !str_starts_with($line, '-')) {
            // Any other non-removal line just advances the counter.
            $currentLine++;
        }
    }

    return $addedLines;
}

/**
 * Find this script's previously posted report comment, identified by COMMENT_MARKER.
 *
 * @return int|null the comment id, or null if none exists / the gh lookup failed
 */
function findExistingCommentId(string $prNum, string $repo): ?int
{
    exec(sprintf(
        'gh api repos/%s/issues/%s/comments --paginate',
        escapeshellarg($repo),
        escapeshellarg($prNum)
    ), $out, $exitCode);

    if ($exitCode !== 0) {
        return null;
    }

    $comments = json_decode(implode('', $out), true) ?? [];

    foreach ($comments as $comment) {
        if (str_contains($comment['body'], COMMENT_MARKER)) {
            return (int)$comment['id'];
        }
    }

    return null;
}

/**
 * Upsert the coverage report comment: PATCH the existing marked comment if one is
 * found, otherwise create a new one. Throws if the gh call fails.
 */
function postPrComment(string $body): void
{
    $prNum = getenv('PR_NUMBER') ?: throw new \Exception("PR_NUMBER env variable not set.");
    $repo  = getenv('GITHUB_REPOSITORY') ?: throw new \Exception("GITHUB_REPOSITORY env variable not set.");

    $body = COMMENT_MARKER . "\n" . $body;

    $existingId = findExistingCommentId($prNum, $repo);

    if ($existingId !== null) {
        exec(sprintf(
            'gh api --method PATCH repos/%s/issues/comments/%s -f body=%s',
            escapeshellarg($repo),
            escapeshellarg((string)$existingId),
            escapeshellarg($body)
        ), $out, $exitCode);
    } else {
        exec(sprintf(
            'gh pr comment %s --body %s',
            escapeshellarg($prNum),
            escapeshellarg($body)
        ), $out, $exitCode);
    }

    if ($exitCode !== 0) {
        throw new \Exception("gh pr comment failed: " . implode("\n", $out));
    }
}

/**
 * Build the markdown "Changed files" table: every changed file with its patch coverage.
 * Files with no clover-tracked executable lines (pure comments/whitespace, or absent from
 * the coverage report) show as n/a.
 *
 * @param array<string, int[]>                       $addedLines   per-file added line numbers
 * @param array<string, array{covered:int,total:int}> $perFileStats per-file covered/total tally
 */
function buildChangedFilesTable(array $addedLines, array $perFileStats): string
{
    $table = "<details>\n<summary>Changed files</summary>\n\n<table>\n"
        . "<thead><tr><th>File</th><th>Patch coverage</th></tr></thead>\n<tbody>\n";

    foreach (array_keys($addedLines) as $file) {
        $stats    = $perFileStats[$file] ?? null;
        $coverage = $stats === null
            ? 'n/a'
            : sprintf('%.2f%% (%d/%d)', ($stats['covered'] / $stats['total']) * 100, $stats['covered'], $stats['total']);

        $table .= sprintf("<tr><td><code>%s</code></td><td>%s</td></tr>\n", htmlspecialchars($file, ENT_QUOTES), $coverage);
    }

    return $table . "</tbody>\n</table>\n</details>";
}




const COMMENT_MARKER = '<!-- pr-coverage-report -->';


$cloverCoverage  = parseClover($prCloverFile);
$addedLines      = getAddedLines($baseBranch);
$repoRoot        = rtrim((string)shell_exec('git rev-parse --show-toplevel'), "\n");
$commitHash      = rtrim((string)shell_exec('git rev-parse HEAD'), "\n");
$overallCoverage = getOverallCoverage($prCloverFile);
$masterCoverage  = getOverallCoverage($masterCloverFile);

$totalExecutable = 0;   // new executable lines added by this PR (tracked by clover)
$coveredAdded    = 0;   // of those, how many are covered by tests
$uncoveredFiles  = [];
$perFileStats    = [];  // relPath => ['covered' => int, 'total' => int] — per-file patch coverage

foreach ($addedLines as $relPath => $lineNums) {
    // git reports repo-relative paths, clover stores absolute ones — bridge with the repo root.
    $fileCoverage = $cloverCoverage[$repoRoot . '/' . $relPath] ?? null;

    if ($fileCoverage === null) {
        continue;
    }

    foreach ($lineNums as $lineNum) {
        // Added lines absent from coverage are non-executable (comments, blanks, braces) — ignore them.
        if (!isset($fileCoverage[$lineNum])) {
            continue;
        }

        $totalExecutable++;
        $perFileStats[$relPath]['total']   = ($perFileStats[$relPath]['total'] ?? 0) + 1;
        $perFileStats[$relPath]['covered'] ??= 0;

        if ($fileCoverage[$lineNum]) {
            $coveredAdded++;
            $perFileStats[$relPath]['covered']++;
        } else {
            $uncoveredFiles[$relPath][] = $lineNum;
        }
    }
}

// Master deltas are report-only — they appear in the table but do not gate the build.
$lineDelta   = $overallCoverage['line'] - $masterCoverage['line'];
$methodDelta = $overallCoverage['method'] - $masterCoverage['method'];

printf("Overall line coverage:   %.2f%%\n", $overallCoverage['line']);
printf("Overall method coverage: %.2f%%\n", $overallCoverage['method']);
printf("Master line coverage:    %.2f%%\n", $masterCoverage['line']);
printf("Master method coverage:  %.2f%%\n", $masterCoverage['method']);

$rows = [
    sprintf('Coverage report for commit: %s', $commitHash),
    '',
    '## Coverage Report',
    '',
    '| Metric | PR | Master | Master Change |',
    '| --- | --- | --- | --- |',
    sprintf('| Line coverage | %.2f%% | %.2f%% | %+.2f%% |', $overallCoverage['line'], $masterCoverage['line'], $lineDelta),
    sprintf('| Method coverage | %.2f%% | %.2f%% | %+.2f%% |', $overallCoverage['method'], $masterCoverage['method'], $methodDelta),
];

// No new executable lines (docs/test-only/refactor PRs): nothing to gate — pass.
if ($totalExecutable === 0) {
    echo "No new executable statements — skipping patch coverage check.\n";

    $rows[] = '';
    $rows[] = 'No new executable statements — patch coverage check skipped.';
    $rows[] = '';
    $rows[] = buildChangedFilesTable($addedLines, $perFileStats);
    $rows[] = '';
    $rows[] = ':white_check_mark: Coverage check passed.';

    postPrComment(implode("\n", $rows));

    printf("Peak memory: %.2f MB\n", memory_get_peak_usage(true) / 1024 / 1024);
    exit(0);
}

// The only gate: enough of the newly added lines must be covered.
$patchCoverage = ($coveredAdded / $totalExecutable) * 100;
$passed        = $patchCoverage >= $minPatchCoverage;

printf("Patch coverage: %.2f%% (%d/%d new statements covered)\n", $patchCoverage, $coveredAdded, $totalExecutable);

if (!empty($uncoveredFiles)) {
    echo "\nUncovered new lines:\n";
    foreach ($uncoveredFiles as $file => $lines) {
        printf("  %s: lines %s\n", $file, implode(', ', $lines));
    }
}

$rows[] = sprintf('| PR patch coverage | %.2f%% (%d/%d statements) | — | — |', $patchCoverage, $coveredAdded, $totalExecutable);

$rows[] = '';
$rows[] = buildChangedFilesTable($addedLines, $perFileStats);
$rows[] = '';

if ($passed) {
    $rows[] = sprintf(':white_check_mark: **PASS**: Patch coverage %.2f%% meets minimum %.2f%%', $patchCoverage, $minPatchCoverage);
} else {
    $rows[] = sprintf(':x: **FAIL**: Patch coverage %.2f%% is below minimum %.2f%%', $patchCoverage, $minPatchCoverage);
}

postPrComment(implode("\n", $rows));

if (!$passed) {
    printf("\nFAIL\n");
    printf("Peak memory: %.2f MB\n", memory_get_peak_usage(true) / 1024 / 1024);
    exit(1);
}

printf("\nPASS\n");
printf("Peak memory: %.2f MB\n", memory_get_peak_usage(true) / 1024 / 1024);
exit(0);