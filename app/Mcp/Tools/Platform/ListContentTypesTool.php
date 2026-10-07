<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Platform;

use App\Enums\SocialAccount\Platform;
use App\Http\Resources\Api\PlatformContentTypesResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List every platform with its content types and their rules. Per content type: max/min media count, requires_media, accept_images/accept_videos/accept_documents, accepts_gif, accepts_mov, forbids_mixed_media, max_video_duration_sec (null = no cap), max_image_bytes / max_video_bytes / max_document_bytes, aspect_ratio_min / aspect_ratio_max for images and video_aspect_ratio_min / video_aspect_ratio_max for videos (width / height, null = not checked; GIFs are never checked; auto_fits_image when images are fitted into the frame on publish instead), image_min_width / image_min_height / image_max_width / image_max_height in pixels, supports_alt_text, supports_user_tags (Instagram tags in media meta.user_tags), supports_video_cover (meta.cover_offset_ms), captionless (stories publish no text, so the text is neither sent nor measured), document_must_be_alone (a PDF must be the only attachment), supports_thread_replies and max_thread_replies (threads on X, Bluesky and Mastodon through meta.thread_replies). Per platform: allowed_media_types, default_content_type, max_content_length (the default text limit of the network; the limit of each account, such as 25000 for an X account with long posts, is max_content_length in list-social-accounts-tool), max_hashtags (Instagram: 5), alt_text_max_length and required_meta (meta needed before a post is scheduled or published; Google Business events and offers also need meta.event, and a YouTube post needs meta.title or text). Media rules never block a draft: they are checked when a post is scheduled, queued or published (create-post-tool, create-posts-tool, update-post-tool, publish-post-tool), and a post whose media breaks a rule of its content_type is rejected with a per-platform error. Call this before attaching media or choosing a content_type.')]
class ListContentTypesTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        return Response::structured([
            'platforms' => PlatformContentTypesResource::collection(Platform::cases())->resolve(),
        ]);
    }
}
