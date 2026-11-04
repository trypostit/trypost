<?php

declare(strict_types=1);

namespace App\Services\Social\Concerns;

use App\Exceptions\Social\SocialPublishException;
use App\Exceptions\TokenExpiredException;
use App\Models\Post;
use App\Support\FirstComment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Posts `meta.first_comment` on a post that just went live, as its author. The
 * post is already public, so a failed comment never fails it: the outcome is
 * kept in `meta.first_comment_result` for the post to show. That key is also
 * the checkpoint: a post that already has its comment is never commented again,
 * while a post created again after a lost response gets its own.
 */
trait PublishesFirstComment
{
    /**
     * Comments on the network's post and returns the comment's id.
     */
    abstract protected function createFirstComment(string $postId, string $text): string;

    /**
     * Renews the access token the next request sends.
     */
    abstract protected function refreshAccessToken(): void;

    protected function publishFirstComment(Post $post, string $postId): void
    {
        $text = FirstComment::text($post->meta);

        if ($text === null || data_get($post->meta, FirstComment::RESULT_KEY.'.post_id') === $postId) {
            return;
        }

        try {
            $commentId = $this->createFirstCommentRefreshingOnce($postId, $text);
        } catch (Throwable $e) {
            Log::error("{$post->platform->label()} first comment failed", [
                'account_id' => $post->social_account_id,
                'post_id' => $postId,
                'error' => $e->getMessage(),
                ...($e instanceof SocialPublishException || $e instanceof TokenExpiredException ? $e->context() : []),
            ]);

            $this->rememberFirstComment($post, ['post_id' => $postId, 'failed' => true]);

            return;
        }

        $this->rememberFirstComment($post, ['post_id' => $postId, 'comment_id' => $commentId]);
    }

    private function createFirstCommentRefreshingOnce(string $postId, string $text): string
    {
        try {
            return $this->createFirstComment($postId, $text);
        } catch (TokenExpiredException) {
            $this->refreshAccessToken();

            return $this->createFirstComment($postId, $text);
        }
    }

    /**
     * @param  array{post_id: string, comment_id?: string, failed?: true}  $result
     */
    private function rememberFirstComment(Post $post, array $result): void
    {
        $post->writePublication(['meta' => [...($post->meta ?? []), FirstComment::RESULT_KEY => $result]]);
    }
}
