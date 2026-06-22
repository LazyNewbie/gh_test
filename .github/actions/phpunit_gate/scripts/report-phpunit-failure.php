<?php
declare(strict_types=1);

/**
 * Posts a PR comment reporting that the PHPUnit run itself failed.
 *
 * Runs from the workflow only when the "Run test suite and get coverage report" step fails,
 * so check-coverage.php never gets a clover report to act on. Reuses the same marked comment
 * (COMMENT_MARKER) so this message replaces / sits in place of the coverage report.
 *
 * Usage: php report-phpunit-failure.php <prNumber> <repository>
 */

require_once __DIR__ . '/shared.php';


$prNumber   = $argv[1] ?? throw new Exception("[arg 1] PR number not provided.");
$repository = $argv[2] ?? throw new Exception("[arg 2] Repository name is not provided.");
$commitHash = $argv[3] ?? throw new Exception("[arg 3] Commit hash is not provided.");

$body = implode("\n", [
    sprintf('Coverage report for commit: %s', $commitHash),
    '',
    '## Coverage Report',
    '',
    ':x:**FAIL**: phpunit execution failed',
]);

postPrComment($body, $prNumber, $repository);

echo "Posted phpunit failure comment.\n";
exit(0);
