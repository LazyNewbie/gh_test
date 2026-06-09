<?php
declare(strict_types=1);

// Marker hidden in the PR comment body so the upsert logic can find its own comment.
const COMMENT_MARKER = '<!-- pr-coverage-report -->';

/**
 * Find this tooling's previously posted comment on the PR, identified by COMMENT_MARKER.
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
 * Upsert the marked PR comment: PATCH the existing one if found, otherwise create a new one.
 * Reads PR_NUMBER / GITHUB_REPOSITORY from the environment. Throws if the gh call fails.
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
