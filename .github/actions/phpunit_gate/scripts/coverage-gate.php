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
 * Usage: php check-coverage.php <minPatchCoverage> <prClover> <masterClover> <baseBranch> <prNumber> <repository>
 */


require_once __DIR__ . '/shared.php';


$minPatchCoverage   = (int)$argv[1] ?? throw new Exception("[arg 1] Minimum PR coverage not provided.");  // percentage 1..100
$prCloverFile       = $argv[2] ?? throw new Exception("[arg 2] PR clover.xml path not provided.");
$masterCloverFile   = $argv[3] ?? throw new Exception("[arg 3] Master branch clover.xml path not provided.");
$baseBranch         = $argv[4] ?? throw new Exception("[arg 4] Base branch not provided.");               // usually origin/master
$prNumber           = $argv[5] ?? throw new Exception("[arg 5] PR number not provided.");
$repository         = $argv[6] ?? throw new Exception("[arg 6] Repository name is not provided.");
$repoLocation       = $argv[7] ?? throw new Exception("[arg 7] Repository location dir is not provided.");


function validateInput(int $minPatchCoverage, string $prCloverFile, string $masterCloverFile): void
{
    if ($minPatchCoverage < 0 || $minPatchCoverage > 100) {
        throw new \Exception("Invalid \$minPatchCoverage value: $minPatchCoverage");
    }

    if (!file_exists($prCloverFile)) {
        throw new \Exception("Invalid file path $prCloverFile");
    }

    if (!file_exists($masterCloverFile)) {
        throw new \Exception("Invalid file path $masterCloverFile");
    }
}


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
function getAddedLines(string $baseBranch, string $repoLocation): array
{
    // `--unified=0` drops context lines so every `+` is a genuine addition;
    // `--diff-filter=ACM` limits to added/copied/modified files.
    $output = git(
        $repoLocation,
        sprintf('diff %s...HEAD --unified=0 --diff-filter=ACM -- "*.php"', escapeshellarg($baseBranch))
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
 * Build the "Changed files" table: each clover-tracked changed file with its patch coverage.
 * Files with no tracked executable lines (pure comments/whitespace, or absent from the
 * coverage report) are omitted.
 *
 * @param array<string, array{covered:int,total:int}> $perFileStats per-file covered/total tally
 */
function buildChangedFilesTable(array $perFileStats): string
{
    $table = "<details>\n<summary>Changed files</summary>\n\n<table>\n"
        . "<thead><tr><th>File</th><th>Patch coverage</th></tr></thead>\n<tbody>\n";

    foreach ($perFileStats as $file => $stats) {
        $coverage = sprintf('%.2f%% (%d/%d)', ($stats['covered'] / $stats['total']) * 100, $stats['covered'], $stats['total']);

        $table .= sprintf("<tr><td><code>%s</code></td><td>%s</td></tr>\n", htmlspecialchars($file, ENT_QUOTES), $coverage);
    }

    return $table . "</tbody>\n</table>\n</details>";
}


validateInput($minPatchCoverage, $prCloverFile, $masterCloverFile);


$cloverCoverage  = parseClover($prCloverFile);
$addedLines      = getAddedLines($baseBranch, $repoLocation);
$repoRoot        = rtrim(implode("\n", git($repoLocation, 'rev-parse --show-toplevel')), "\n");
$commitHash      = rtrim(implode("\n", git($repoLocation, 'rev-parse HEAD')), "\n");
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

printf("Base branch:             %s\n",     $baseBranch);
printf("Overall line coverage:   %.2f%%\n", $overallCoverage['line']);
printf("Overall method coverage: %.2f%%\n", $overallCoverage['method']);
printf("Master line coverage:    %.2f%%\n", $masterCoverage['line']);
printf("Master method coverage:  %.2f%%\n", $masterCoverage['method']);

$commentRows = [
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

    $commentRows[] = '';
    $commentRows[] = 'No new executable statements — patch coverage check skipped.';
    $commentRows[] = '';
    $commentRows[] = buildChangedFilesTable($perFileStats);
    $commentRows[] = '';
    $commentRows[] = ':white_check_mark: Coverage check passed.';
    $passed = true;

} else {
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

    $commentRows[] = sprintf('| PR patch coverage | %.2f%% (%d/%d statements) | — | — |', $patchCoverage, $coveredAdded, $totalExecutable);
    $commentRows[] = '';
    $commentRows[] = buildChangedFilesTable($perFileStats);
    $commentRows[] = '';

    if ($passed) {
        $commentRows[] = sprintf(':white_check_mark:**PASS**: Patch coverage %.2f%% meets minimum %.2f%%', $patchCoverage, $minPatchCoverage);
    } else {
        $commentRows[] = sprintf(':x:**FAIL**: Patch coverage %.2f%% is below minimum %.2f%%', $patchCoverage, $minPatchCoverage);
    }
}


if ($passed) {
    postPrComment(implode("\n", $commentRows), $prNumber, $repository);
    printf("\nPASS\n");
    printf("Peak memory: %.2f MB\n", memory_get_peak_usage(true) / 1024 / 1024);
    exit(0);
} else {
    printf("\nFAIL\n");
    printf("Peak memory: %.2f MB\n", memory_get_peak_usage(true) / 1024 / 1024);
    exit(1);
}