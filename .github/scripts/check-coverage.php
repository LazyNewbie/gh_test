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

function getOverallCoverage(string $cloverFile): array
{
    $xml     = simplexml_load_file($cloverFile);
    $metrics = $xml->project->metrics;

    $totalStmts     = (int) $metrics['statements'];
    $coveredStmts   = (int) $metrics['coveredstatements'];
    $totalMethods   = (int) $metrics['methods'];
    $coveredMethods = (int) $metrics['coveredmethods'];

    return [
        'line'   => $totalStmts > 0 ? ($coveredStmts / $totalStmts) * 100 : 0.0,
        'method' => $totalMethods > 0 ? ($coveredMethods / $totalMethods) * 100 : 0.0,
    ];
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

const COMMENT_MARKER = '<!-- patch-coverage-report -->';

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
            return (int) $comment['id'];
        }
    }

    return null;
}

function postPrComment(string $body): void
{
    $prNum = getenv('PR_NUMBER') ?: throw new \Exception("PR_NUMBER env variable not set.");
    $repo  = getenv('GITHUB_REPOSITORY') ?: throw new \Exception("GITHUB_REPOSITORY env variable not set.");

    $body = COMMENT_MARKER . "\n" . $body;

    echo "Posting PR comment:\n$body\n";

    $existingId = findExistingCommentId($prNum, $repo);

    if ($existingId !== null) {
        exec(sprintf(
            'gh api --method PATCH repos/%s/issues/comments/%s -f body=%s',
            escapeshellarg($repo),
            escapeshellarg((string) $existingId),
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

$lineDelta     = $overallCoverage['line'] - $masterCoverage['line'];
$methodDelta   = $overallCoverage['method'] - $masterCoverage['method'];
$overallPassed = $overallCoverage['line'] >= $masterCoverage['line'] && $overallCoverage['method'] >= $masterCoverage['method'];

printf("Overall line coverage:   %.2f%%\n", $overallCoverage['line']);
printf("Overall method coverage: %.2f%%\n", $overallCoverage['method']);
printf("Master line coverage:    %.2f%%\n", $masterCoverage['line']);
printf("Master method coverage:  %.2f%%\n", $masterCoverage['method']);

$rows = [
    '## Coverage Report',
    '',
    '| Metric | PR | Master | Change |',
    '| --- | --- | --- | --- |',
    sprintf('| Line coverage | %.2f%% | %.2f%% | %+.2f%% |', $overallCoverage['line'], $masterCoverage['line'], $lineDelta),
    sprintf('| Method coverage | %.2f%% | %.2f%% | %+.2f%% |', $overallCoverage['method'], $masterCoverage['method'], $methodDelta),
];

if ($totalExecutable === 0) {
    echo "No new executable statements — skipping patch coverage check.\n";

    $rows[] = '';
    $rows[] = 'No new executable statements — patch coverage check skipped.';
    $rows[] = '';
    $rows[] = $overallPassed
        ? ':white_check_mark: Overall coverage has not decreased.'
        : sprintf(':x: **FAIL**: Overall coverage decreased (line: %+.2f%%, method: %+.2f%%)', $lineDelta, $methodDelta);

    postPrComment(implode("\n", $rows));

    if (!$overallPassed) {
        printf("\nFAIL: Overall coverage decreased\n");
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

$rows[] = sprintf('| PR patch coverage | %.2f%% (%d/%d statements) | — | — |', $patchCoverage, $coveredAdded, $totalExecutable);
$rows[] = '';

if (!$patchPassed) {
    $rows[] = sprintf(':x: **FAIL**: Patch coverage %.2f%% is below minimum %.2f%%', $patchCoverage, $minPatchCoverage);
}
if (!$overallPassed) {
    $rows[] = sprintf(':x: **FAIL**: Overall coverage decreased (line: %+.2f%%, method: %+.2f%%)', $lineDelta, $methodDelta);
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