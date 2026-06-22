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
 */
function postPrComment(string $body, string $prNum, string $repo): void
{

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
            'gh pr comment %s --repo %s --body %s',
            escapeshellarg($prNum),
            escapeshellarg($repo),
            escapeshellarg($body)
        ), $out, $exitCode);
    }

    if ($exitCode !== 0) {
        throw new \Exception("gh pr comment failed: " . implode("\n", $out));
    }
}

function git(string $repoLocation, string $arguments): array {
    exec(sprintf('git -C %s %s 2>&1', escapeshellarg($repoLocation), $arguments), $output, $exitCode);
    if ($exitCode !== 0) {
        throw new \Exception("git failed: " . implode("\n", $output));
    }
    return $output;
}
