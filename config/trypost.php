<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Self-Hosted Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, the application runs in self-hosted mode which skips
    | payment/subscription requirements during onboarding.
    |
    */

    'self_hosted' => env('SELF_HOSTED', true),

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | SafeHttpFetcher blocks requests to private/reserved IP ranges (SSRF
    | protection) by default. Self-hosted operators who need to fetch from
    | their own internal network (e.g. an internal RSS feed or webhook) can
    | opt in here. Leave disabled unless you understand the SSRF risk.
    |
    */

    'security' => [
        'allow_private_network' => (bool) env('TRYPOST_ALLOW_PRIVATE_NETWORK', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing
    |--------------------------------------------------------------------------
    |
    | Control trial behavior for SaaS billing:
    | - true: require card at checkout to start trial (Stripe trialing)
    | - false: grant generic trial at signup without card
    |
    */

    'billing' => [
        'require_card_for_trial' => (bool) env('REQUIRE_CARD_FOR_TRIAL', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media Size Limits
    |--------------------------------------------------------------------------
    |
    | Per-type size caps in megabytes. Single source of truth — direct
    | uploads (StoreAssetRequest, AssetController::storeChunked), URL
    | fetches (MediaAttacher), and the MediaType enum all read from here.
    |
    */

    'media' => [
        'max_size_mb' => [
            'image' => (int) env('MEDIA_IMAGE_MAX_SIZE_MB', 10),
            'video' => (int) env('MEDIA_VIDEO_MAX_SIZE_MB', 1024),
            // LinkedIn caps document (PDF carousel) uploads at 100MB.
            'document' => (int) env('MEDIA_DOCUMENT_MAX_SIZE_MB', 100),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google Authentication
    |--------------------------------------------------------------------------
    |
    | Enable or disable "Login with Google" on the login and register pages.
    | Disable this if you don't have Google OAuth credentials configured.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Outbound User-Agent
    |--------------------------------------------------------------------------
    |
    | Branded User-Agent applied to outbound HTTP from automation nodes
    | (webhook + http_request) so recipients know the request came from
    | TryPost.it. Self-hosters can override it.
    |
    */

    'user_agent' => env('TRYPOST_USER_AGENT', 'TryPost.it/1.0 (+https://trypost.it)'),

    'google_auth_enabled' => env('GOOGLE_AUTH_ENABLED', false),

    'github_auth_enabled' => env('GITHUB_AUTH_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Social Platforms
    |--------------------------------------------------------------------------
    |
    | Configure which social platforms are enabled in the application.
    | Set to false to temporarily disable a platform (e.g., when credentials
    | are revoked, expired, or pending approval).
    |
    */

    'platforms' => [
        'linkedin' => [
            'enabled' => env('LINKEDIN_ENABLED', true),
            'api' => env('LINKEDIN_API', 'https://api.linkedin.com'),
            // OAuth host is different from the data API (api.linkedin.com).
            'oauth_api' => env('LINKEDIN_OAUTH_API', 'https://www.linkedin.com'),
            // Scopes for LinkedIn authentication
            'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env('LINKEDIN_SCOPES', 'openid,profile,email,w_member_social'))))),
        ],
        'linkedin-page' => [
            'enabled' => env('LINKEDIN_PAGE_ENABLED', true),
            'api' => env('LINKEDIN_PAGE_API', 'https://api.linkedin.com'),
            // Scopes for LinkedIn Page authentication
            'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env('LINKEDIN_PAGE_SCOPES', 'openid,profile,email,w_organization_social,r_organization_social,rw_organization_admin,w_member_social'))))),
        ],
        'x' => [
            'enabled' => env('X_ENABLED', true),
            'api' => env('X_API', 'https://api.x.com/2'),
        ],
        'tiktok' => [
            'enabled' => env('TIKTOK_ENABLED', true),
            'api' => env('TIKTOK_API', 'https://open.tiktokapis.com/v2'),
        ],
        'youtube' => [
            'enabled' => env('YOUTUBE_ENABLED', true),
            'data_api' => env('YOUTUBE_DATA_API', 'https://www.googleapis.com/youtube/v3'),
            'analytics_api' => env('YOUTUBE_ANALYTICS_API', 'https://youtubeanalytics.googleapis.com/v2'),
            'oauth_api' => env('YOUTUBE_OAUTH_API', 'https://oauth2.googleapis.com'),
        ],
        'facebook' => [
            'enabled' => env('FACEBOOK_ENABLED', true),
            'graph_api' => env('FACEBOOK_GRAPH_API', 'https://graph.facebook.com/v25.0'),
        ],
        'instagram' => [
            'enabled' => env('INSTAGRAM_ENABLED', true),
            'graph_api' => env('INSTAGRAM_GRAPH_API', 'https://graph.instagram.com/v25.0'),
            // graph.instagram.com (no version) is the auth/refresh host.
            'auth_api' => env('INSTAGRAM_AUTH_API', 'https://graph.instagram.com'),
        ],
        'instagram-facebook' => [
            'enabled' => env('INSTAGRAM_FACEBOOK_ENABLED', true),
            'graph_api' => env('INSTAGRAM_FACEBOOK_GRAPH_API', 'https://graph.facebook.com/v25.0'),
        ],
        'threads' => [
            'enabled' => env('THREADS_ENABLED', true),
            'graph_api' => env('THREADS_GRAPH_API', 'https://graph.threads.net/v1.0'),
            // graph.threads.net (no version) is the auth/refresh host.
            'auth_api' => env('THREADS_AUTH_API', 'https://graph.threads.net'),
        ],
        'pinterest' => [
            'enabled' => env('PINTEREST_ENABLED', true),
            'api' => env('PINTEREST_API', 'https://api.pinterest.com/v5'),
        ],
        'bluesky' => [
            'enabled' => env('BLUESKY_ENABLED', true),
            'public_appview' => env('BLUESKY_PUBLIC_APPVIEW', 'https://public.api.bsky.app'),
            // Default PDS used when the account has no `meta.service` override.
            'default_service' => env('BLUESKY_DEFAULT_SERVICE', 'https://bsky.social'),
            // Web client where published posts are viewed (profile/post URLs).
            'web_app' => env('BLUESKY_WEB_APP', 'https://bsky.app'),
            // Video upload service (separate from the PDS). Videos are processed
            // here, then the resulting blob is embedded in the post record.
            'video_service' => env('BLUESKY_VIDEO_SERVICE', 'https://video.bsky.app'),
            'video_service_did' => env('BLUESKY_VIDEO_SERVICE_DID', 'did:web:video.bsky.app'),
            // Seconds between transcode job-status polls.
            'video_poll_seconds' => env('BLUESKY_VIDEO_POLL_SECONDS', 2),
            // Bluesky rejects videos larger than 100 MB; skip oversized files early.
            'video_max_bytes' => env('BLUESKY_VIDEO_MAX_BYTES', 100 * 1024 * 1024),
            // PLC directory, used to resolve an account's real PDS host from its DID.
            'plc_directory' => env('BLUESKY_PLC_DIRECTORY', 'https://plc.directory'),
        ],
        'mastodon' => [
            'enabled' => env('MASTODON_ENABLED', true),
            // Default instance used when the account has no `meta.instance` override.
            'default_instance' => env('MASTODON_DEFAULT_INSTANCE', 'https://mastodon.social'),
        ],
        'telegram' => [
            'enabled' => env('TELEGRAM_ENABLED', true),
            // Single shared bot (BotFather). Users add it as admin to their channel.
            'bot_token' => env('TELEGRAM_BOT_TOKEN'),
            'bot_username' => env('TELEGRAM_BOT_USERNAME'),
            'api' => env('TELEGRAM_API', 'https://api.telegram.org'),
            // Secret-token header Telegram echoes on every webhook call.
            'webhook_secret' => env('TELEGRAM_WEBHOOK_SECRET'),
        ],
        'discord' => [
            'enabled' => env('DISCORD_ENABLED', true),
            // Single shared bot application. OAuth (bot scope) authorizes adding the
            // bot to the user's server; channel listing, mentions and posting all
            // use this bot token, not the user's OAuth token.
            'bot_token' => env('DISCORD_BOT_TOKEN'),
            'api' => env('DISCORD_API', 'https://discord.com/api/v10'),
            'oauth_api' => env('DISCORD_OAUTH_API', 'https://discord.com/api/oauth2'),
            // Permission bitfield requested for the bot: VIEW_CHANNEL (1<<10) +
            // SEND_MESSAGES (1<<11) + EMBED_LINKS (1<<14) + ATTACH_FILES (1<<15) +
            // READ_MESSAGE_HISTORY (1<<16) + MENTION_EVERYONE (1<<17) = 248832.
            'permissions' => env('DISCORD_PERMISSIONS', '248832'),
            'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env('DISCORD_SCOPES', 'bot,identify,guilds'))))),
        ],
    ],

];
