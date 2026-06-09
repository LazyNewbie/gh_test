<?php
declare(strict_types=1);

/**
 * Posts a PR comment reporting that the PHPUnit run itself failed.
 *
 * Runs from the workflow only when the "Run test suite and get coverage report" step fails,
 * so check-coverage.php never gets a clover report to act on. Reuses the same marked comment
 * (COMMENT_MARKER) so this message replaces / sits in place of the coverage report.
 *
 * Usage: php report-phpunit-failure.php
 * Env:   PR_NUMBER, GITHUB_REPOSITORY (used by postPrComment for the gh API calls).
 */

require_once __DIR__ . '/shared.php';

$commitHash = rtrim((string)shell_exec('git rev-parse HEAD'), "\n");

$body = implode("\n", [
    sprintf('Coverage report for commit: %s', $commitHash),
    '',
    '## Coverage Report',
    '',
    'phpunit execution :x:**FAIL**',
]);

postPrComment($body);

echo "Posted phpunit failure comment.\n";
exit(0);
