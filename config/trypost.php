<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Self-Hosted Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, the application runs in self-hosted mode which skips
    | payment/subscription requirements during welcome.
    |
    */

    'self_hosted' => env('SELF_HOSTED', true),

    /*
    |--------------------------------------------------------------------------
    | Legal pages
    |--------------------------------------------------------------------------
    |
    | Linked from the auth screens. Platform app reviews (TikTok explicitly)
    | require Terms and Privacy links to be clearly visible; self-hosted
    | installs point these at wherever they publish their own documents.
    |
    */

    'legal' => [
        'terms_url' => env('LEGAL_TERMS_URL', 'https://trypost.it/terms'),
        'privacy_url' => env('LEGAL_PRIVACY_URL', 'https://trypost.it/privacy'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta page walk budget
    |--------------------------------------------------------------------------
    |
    | Seconds the Facebook/Instagram page walk may spend before it returns what
    | it has and reports itself incomplete. It runs inside the OAuth callback,
    | so this must stay well under the web server's request timeout.
    |
    */

    'meta_page_walk_seconds' => (int) env('META_PAGE_WALK_SECONDS', 20),

    /*
    |--------------------------------------------------------------------------
    | Security
    |--------------------------------------------------------------------------
    |
    | SafeHttpFetcher blocks requests to private/reserved IP ranges (SSRF
    | protection) by default. Self-hosted operators who need to fetch from
    | their own internal network (e.g. an internal webhook endpoint) can
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
    | Control whether signup requires a card before app access:
    | - true: no generic trial at signup; access only after Stripe Checkout
    |   (trialDays and/or first-month coupon come from cashier.* env knobs)
    | - false: grant generic trial at signup without a card
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
    | signed_upload_url_ttl_minutes controls the temporary signed POST URL
    | issued for api.uploads.store (MCP / direct upload flow).
    | MEDIA_SIGNED_UPLOAD_URL_TTL_MINUTES is preferred; MCP_UPLOAD_URL_TTL_MINUTES
    | remains as a legacy fallback. MCP_UPLOAD_MAX_SIZE_MB was removed — size
    | caps come from max_size_mb above (uploads stream to storage).
    |
    | signed_upload_per_*_per_minute backs the signed-uploads rate limiter:
    | workspace bucket first (tenants isolated on shared MCP egress), then a
    | high IP backstop.
    |
    */

    'media' => [
        'max_size_mb' => [
            'image' => (int) env('MEDIA_IMAGE_MAX_SIZE_MB', 10),
            'video' => (int) env('MEDIA_VIDEO_MAX_SIZE_MB', 1024),
            // LinkedIn caps document (PDF carousel) uploads at 100MB.
            'document' => (int) env('MEDIA_DOCUMENT_MAX_SIZE_MB', 100),
        ],
        'signed_upload_url_ttl_minutes' => (int) (env('MEDIA_SIGNED_UPLOAD_URL_TTL_MINUTES') ?? env('MCP_UPLOAD_URL_TTL_MINUTES', 15)),
        'signed_upload_per_workspace_per_minute' => (int) env('MEDIA_SIGNED_UPLOAD_PER_WORKSPACE_PER_MINUTE', 60),
        'signed_upload_per_ip_per_minute' => (int) env('MEDIA_SIGNED_UPLOAD_PER_IP_PER_MINUTE', 1200),
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
    | Branded User-Agent applied to outbound HTTP from workspace webhooks so
    | recipients know the request came from TryPost.it. Self-hosters can
    | override it.
    |
    */

    'user_agent' => env('TRYPOST_USER_AGENT', 'TryPost.it/1.0 (+https://trypost.it)'),

    /*
    |--------------------------------------------------------------------------
    | Repurpose
    |--------------------------------------------------------------------------
    |
    | How often an active repurpose polls its source network for videos the
    | workspace published outside TryPost. The scheduler ticks every five
    | minutes and each repurpose is polled when it is due, so the interval is
    | a runtime knob rather than a cron expression. Meta's Instagram quota is
    | an app-wide pool (200 calls per hour per daily active user), so raise
    | the interval before the pool tightens. `backoff_minutes` is used instead
    | when the source answers with a rate-limit error.
    |
    */

    'repurpose' => [
        'poll_interval_minutes' => (int) env('REPURPOSE_POLL_INTERVAL_MINUTES', 15),
        'backoff_minutes' => (int) env('REPURPOSE_BACKOFF_MINUTES', 60),
    ],

    'google_auth_enabled' => env('GOOGLE_AUTH_ENABLED', false),

    'github_auth_enabled' => env('GITHUB_AUTH_ENABLED', false),

    // Instances behind an identity provider usually want the local password
    // form gone. Ignored while no other provider is configured, so a single
    // variable can never lock everybody out.
    'password_login_enabled' => env('PASSWORD_LOGIN_ENABLED', true),

    'oidc_auth_enabled' => env('OIDC_AUTH_ENABLED', false),
    'oidc_display_name' => env('OIDC_DISPLAY_NAME', 'SSO'),
    // Ends the session at the identity provider too, so logging out really
    // logs out instead of silently signing straight back in.
    'oidc_logout_enabled' => env('OIDC_LOGOUT_ENABLED', true),
    // Where the provider sends the browser after logout. Leave empty unless
    // the exact same URI is registered with the provider - a mismatch makes
    // providers reject the logout entirely.
    'oidc_post_logout_redirect_uri' => env('OIDC_POST_LOGOUT_REDIRECT_URI'),
    // Group handling. The claim is whatever the provider puts the group names
    // in; allowed_groups gates who may sign in at all.
    'oidc_groups_claim' => env('OIDC_GROUPS_CLAIM', 'groups'),
    'oidc_allowed_groups' => env('OIDC_ALLOWED_GROUPS', ''),
    // Self-hosted teams usually want provider group membership to be the only
    // onboarding step, so new OIDC users can be placed on the shared account
    // instead of needing a separate invite each.
    'oidc_auto_join_enabled' => env('OIDC_AUTO_JOIN_ENABLED', false),
    'oidc_auto_join_role' => env('OIDC_AUTO_JOIN_ROLE', 'member'),
    // Groups whose members administer the workspace. Set this and the role of
    // every OIDC user follows the provider on each sign-in, which is what lets
    // an instance run without a standing local admin account.
    'oidc_admin_groups' => env('OIDC_ADMIN_GROUPS', ''),
    // Hand the account over to group management entirely by clearing its
    // owner. Ownership outranks the workspace role, so whoever holds it sits
    // outside the group system for good.
    'oidc_release_ownership' => env('OIDC_RELEASE_OWNERSHIP', false),
    'oidc_auto_join_account_id' => env('OIDC_AUTO_JOIN_ACCOUNT_ID'),

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
            'defuse_links' => (bool) env('X_DEFUSE_LINKS', false),
        ],
        'tiktok' => [
            'enabled' => env('TIKTOK_ENABLED', true),
            'api' => env('TIKTOK_API', 'https://open.tiktokapis.com/v2'),
            // OAuth scopes to request. Trim when the TikTok app lacks a product
            // (e.g. no Display API => drop user.info.profile, user.info.stats,
            // video.list; analytics degrade gracefully, username stays empty).
            'scopes' => array_values(array_filter(array_map('trim', explode(',', (string) env('TIKTOK_SCOPES', 'user.info.basic,user.info.profile,user.info.stats,video.publish,video.upload,video.list'))))),
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
            'rupload_host' => env('FACEBOOK_RUPLOAD_HOST', 'rupload.facebook.com'),
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
            // Gradually back off status checks to at most this interval.
            'video_poll_max_seconds' => env('BLUESKY_VIDEO_POLL_MAX_SECONDS', 30),
            // Bluesky rejects videos over 300 MB (app.bsky.embed.video maxSize = 300000000).
            'video_max_bytes' => env('BLUESKY_VIDEO_MAX_BYTES', 300_000_000),
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
        'google_business' => [
            'enabled' => env('GOOGLE_BUSINESS_ENABLED', true),
            // Account Management API — lists the Business accounts a user administers.
            'account_management_api' => env('GOOGLE_BUSINESS_ACCOUNT_MANAGEMENT_API', 'https://mybusinessaccountmanagement.googleapis.com/v1'),
            // Business Information API — lists locations under an account.
            'business_information_api' => env('GOOGLE_BUSINESS_BUSINESS_INFORMATION_API', 'https://mybusinessbusinessinformation.googleapis.com/v1'),
            // Legacy but still-active v4 API — the only home for Local Post create/update/delete.
            'local_posts_api' => env('GOOGLE_BUSINESS_LOCAL_POSTS_API', 'https://mybusiness.googleapis.com/v4'),
            // Business Profile Performance API — location-level analytics.
            'performance_api' => env('GOOGLE_BUSINESS_PERFORMANCE_API', 'https://businessprofileperformance.googleapis.com/v1'),
            // OAuth token endpoint, same host Google uses for every OAuth2 client.
            'oauth_api' => env('GOOGLE_BUSINESS_OAUTH_API', 'https://oauth2.googleapis.com'),
            // Business Profile web UI — post URL fallback and the social-account profile link.
            'dashboard' => env('GOOGLE_BUSINESS_DASHBOARD', 'https://business.google.com'),
        ],
    ],

];
