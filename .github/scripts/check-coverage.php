<?php

declare(strict_types=1);


$minPatchCoverage = (float) ($argv[1] ?? throw new \Exception("[arg 1] Minimum PR coverage not provided."));    // percentage 50, 80, 100
$cloverFile       = $argv[2] ?? throw new \Exception("[arg 2] clover.xml path not provided.");                  // clover.xml relative file path
$masterCloverFile = $argv[3] ?? throw new \Exception("[arg 3] master clover.xml path not provided.");           // master branch clover.xml relative file path
$baseBranch       = $argv[4] ?? throw new \Exception("[arg 4] base branch not provided.");                      // usualy origin/master


function parseClover(string $path): array
{
    $xml      = simplexml_load_file($path);
    $coverage = [];

    foreach ($xml->project->file as $file) {
        $lines = [];
        foreach ($file->line as $line) {
            if ((string) $line['type'] === 'stmt') {
                $lines[(int) $line['num']] = (int) $line['count'] > 0;
            }
        }
        $coverage[(string) $file['name']] = $lines;
    }

    return $coverage;
}

function getOverallCoverage(string $cloverFile): float
{
    $xml      = simplexml_load_file($cloverFile);
    $metrics  = $xml->project->metrics;
    $total    = (int) $metrics['statements'];
    $covered  = (int) $metrics['coveredstatements'];

    return $total > 0 ? ($covered / $total) * 100 : 0.0;
}

function getAddedLines(string $baseBranch): array
{
    exec(
        sprintf('git diff %s...HEAD --unified=0 --diff-filter=ACM -- "*.php" 2>&1', escapeshellarg($baseBranch)),
        $output,
        $exitCode
    );

    $addedLines  = [];
    $currentFile = null;
    $currentLine = 0;

    foreach ($output as $line) {
        if (str_starts_with($line, '+++ b/')) {
            $currentFile              = substr($line, 6);
            $addedLines[$currentFile] ??= [];
        } elseif (str_starts_with($line, '@@ ')) {
            preg_match('/@@ -\S+ \+(\d+)/', $line, $m);
            $currentLine = (int) $m[1];
        } elseif ($currentFile !== null && str_starts_with($line, '+')) {
            $addedLines[$currentFile][] = $currentLine++;
        } elseif ($currentFile !== null && !str_starts_with($line, '-')) {
            $currentLine++;
        }
    }

    return $addedLines;
}

function postPrComment(string $body): void
{
    $prNum = getenv('PR_NUMBER') ?: throw new \Exception("PR_NUMBER env variable not set.");

    echo "Posting PR comment:\n$body\n";

    exec(sprintf('gh pr comment %s --body %s', escapeshellarg($prNum), escapeshellarg($body)), $out, $exitCode);

    if ($exitCode !== 0) {
        throw new \Exception("gh pr comment failed: " . implode("\n", $out));
    }
}

$cloverCoverage  = parseClover($cloverFile);
$addedLines      = getAddedLines($baseBranch);
$repoRoot        = rtrim((string) shell_exec('git rev-parse --show-toplevel'), "\n");
$overallCoverage = getOverallCoverage($cloverFile);
$masterCoverage  = getOverallCoverage($masterCloverFile);

$totalExecutable = 0;
$coveredAdded    = 0;
$uncoveredFiles  = [];

foreach ($addedLines as $relPath => $lineNums) {
    $fileCoverage = $cloverCoverage[$repoRoot . '/' . $relPath] ?? null;

    if ($fileCoverage === null) {
        continue;
    }

    foreach ($lineNums as $lineNum) {
        if (!isset($fileCoverage[$lineNum])) {
            continue;
        }

        $totalExecutable++;

        if ($fileCoverage[$lineNum]) {
            $coveredAdded++;
        } else {
            $uncoveredFiles[$relPath][] = $lineNum;
        }
    }
}

$delta         = $overallCoverage - $masterCoverage;
$deltaStr      = sprintf('%+.2f%%', $delta);
$overallPassed = $overallCoverage >= $masterCoverage;

printf("Overall coverage: %.2f%%\n", $overallCoverage);
printf("Master coverage:  %.2f%%\n", $masterCoverage);
printf("Delta: %s\n", $deltaStr);

$rows = [
    '## Coverage Report',
    '',
    '| Metric | Value |',
    '| --- | --- |',
    sprintf('| Overall coverage | %.2f%% |', $overallCoverage),
    sprintf('| Master coverage | %.2f%% |', $masterCoverage),
    sprintf('| Change vs master | %s |', $deltaStr),
];

if ($totalExecutable === 0) {
    echo "No new executable statements — skipping patch coverage check.\n";

    $rows[] = '';
    $rows[] = 'No new executable statements — patch coverage check skipped.';
    $rows[] = '';
    $rows[] = $overallPassed
        ? ':white_check_mark: Overall coverage has not decreased.'
        : sprintf(':x: **FAIL**: Overall coverage decreased by %.2f%%', abs($delta));

    postPrComment(implode("\n", $rows));

    if (!$overallPassed) {
        printf("\nFAIL: Overall coverage decreased by %.2f%%\n", abs($delta));
        printf("Peak memory: %.2f MB\n", memory_get_peak_usage(true) / 1024 / 1024);
        exit(1);
    }

    printf("Peak memory: %.2f MB\n", memory_get_peak_usage(true) / 1024 / 1024);
    exit(0);
}

$patchCoverage = ($coveredAdded / $totalExecutable) * 100;
$patchPassed   = $patchCoverage >= $minPatchCoverage;
$passed        = $patchPassed && $overallPassed;

printf("Patch coverage: %.2f%% (%d/%d new statements covered)\n", $patchCoverage, $coveredAdded, $totalExecutable);

if (!empty($uncoveredFiles)) {
    echo "\nUncovered new lines:\n";
    foreach ($uncoveredFiles as $file => $lines) {
        printf("  %s: lines %s\n", $file, implode(', ', $lines));
    }
}

$rows[] = sprintf('| PR patch coverage | %.2f%% (%d/%d statements) |', $patchCoverage, $coveredAdded, $totalExecutable);
$rows[] = '';

if (!$patchPassed) {
    $rows[] = sprintf(':x: **FAIL**: Patch coverage %.2f%% is below minimum %.2f%%', $patchCoverage, $minPatchCoverage);
}
if (!$overallPassed) {
    $rows[] = sprintf(':x: **FAIL**: Overall coverage decreased by %.2f%%', abs($delta));
}
if ($passed) {
    $rows[] = sprintf(':white_check_mark: **PASS**: Patch coverage %.2f%% meets minimum %.2f%%', $patchCoverage, $minPatchCoverage);
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