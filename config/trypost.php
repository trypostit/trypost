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
    | uploads (StoreChunkedMediaRequest), URL
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
        'upload_retention_hours' => (int) env('MEDIA_UPLOAD_RETENTION_HOURS', 24),
        'heic_conversion' => (bool) env('MEDIA_HEIC_CONVERSION', true),
        'heic_limits' => [
            'memory_mb' => (int) env('MEDIA_HEIC_MEMORY_LIMIT_MB', 256),
            'map_mb' => (int) env('MEDIA_HEIC_MAP_LIMIT_MB', 512),
            'disk_mb' => (int) env('MEDIA_HEIC_DISK_LIMIT_MB', 1024),
            'area_mb' => (int) env('MEDIA_HEIC_AREA_LIMIT_MB', 1024),
            'width_px' => (int) env('MEDIA_HEIC_WIDTH_LIMIT_PX', 16384),
            'height_px' => (int) env('MEDIA_HEIC_HEIGHT_LIMIT_PX', 16384),
            'time_seconds' => (int) env('MEDIA_HEIC_TIME_LIMIT_SECONDS', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Composer media sources
    |--------------------------------------------------------------------------
    |
    | A source shows in the composer's media menu only when its flag is on and
    | its credentials (config/services.php) are set. Hosts and SDK URLs are
    | overridable for self-hosters; download_hosts is a comma-separated list
    | where `*.` matches any subdomain.
    |
    */

    'media_sources' => [
        'unsplash' => [
            'api' => env('UNSPLASH_API_URL', 'https://api.unsplash.com'),
            'website' => env('UNSPLASH_WEBSITE_URL', 'https://unsplash.com'),
            'download_hosts' => env('UNSPLASH_DOWNLOAD_HOSTS', 'images.unsplash.com'),
            'requests_per_user_per_minute' => (int) env('UNSPLASH_REQUESTS_PER_MINUTE', 30),
        ],
        'google_oauth' => [
            'authorize_url' => env('GOOGLE_MEDIA_AUTHORIZE_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
            'token_api' => env('GOOGLE_MEDIA_OAUTH_API', 'https://oauth2.googleapis.com'),
        ],
        'google_drive' => [
            'enabled' => (bool) env('GOOGLE_DRIVE_ENABLED', true),
            'api' => env('GOOGLE_DRIVE_API_URL', 'https://www.googleapis.com/drive/v3'),
            'picker_sdk' => env('GOOGLE_PICKER_SDK_URL', 'https://apis.google.com/js/api.js'),
            'download_hosts' => env('GOOGLE_DRIVE_DOWNLOAD_HOSTS', 'www.googleapis.com,*.googleusercontent.com'),
        ],
        'google_photos' => [
            'enabled' => (bool) env('GOOGLE_PHOTOS_ENABLED', false),
            'api' => env('GOOGLE_PHOTOS_PICKER_API_URL', 'https://photospicker.googleapis.com'),
            'download_hosts' => env('GOOGLE_PHOTOS_DOWNLOAD_HOSTS', 'lh3.googleusercontent.com,lh4.googleusercontent.com,lh5.googleusercontent.com,lh6.googleusercontent.com,video-downloads.googleusercontent.com'),
            'polls_per_user_per_minute' => (int) env('GOOGLE_PHOTOS_POLLS_PER_MINUTE', 60),
            'max_items' => (int) env('GOOGLE_PHOTOS_MAX_ITEMS', 10),
        ],
        'canva' => [
            'enabled' => (bool) env('CANVA_ENABLED', true),
            'api' => env('CANVA_API_URL', 'https://api.canva.com/rest/v1'),
            'authorize_url' => env('CANVA_AUTHORIZE_URL', 'https://www.canva.com/api/oauth/authorize'),
            'code_challenge_method' => env('CANVA_CODE_CHALLENGE_METHOD', 's256'),
            'download_hosts' => env('CANVA_DOWNLOAD_HOSTS', '*.canva.com'),
            'editor_hosts' => env('CANVA_EDITOR_HOSTS', '*.canva.com'),
            'export_timeout_seconds' => (int) env('CANVA_EXPORT_TIMEOUT_SECONDS', 120),
            'export_poll_seconds' => (int) env('CANVA_EXPORT_POLL_SECONDS', 2),
        ],
        'import_timeout_seconds' => (int) env('MEDIA_IMPORT_TIMEOUT_SECONDS', 600),
        'imports_per_user_per_minute' => (int) env('MEDIA_IMPORTS_PER_MINUTE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Post history retention
    |--------------------------------------------------------------------------
    |
    | Published and partially published posts older than this many days are
    | deleted with their media by posts:prune-history. Must be 1 or more.
    |
    */

    'posts' => [
        'history_retention_days' => env('POST_HISTORY_RETENTION_DAYS', 730),
    ],

    /*
    |--------------------------------------------------------------------------
    | External posts
    |--------------------------------------------------------------------------
    |
    | Posts published directly on a network become posts in Sent. Only the
    | newest `import_limit` publications of a channel are imported (0 turns
    | importing off); on X only those from the last `x_import_days` days.
    |
    */

    'external_posts' => [
        'import_limit' => (int) env('EXTERNAL_POSTS_IMPORT_LIMIT', 50),
        'x_import_days' => (int) env('X_EXTERNAL_POSTS_IMPORT_DAYS', 30),
        'match_window_minutes' => (int) env('EXTERNAL_POSTS_MATCH_WINDOW_MINUTES', 120),
        'media_requests_per_network_per_minute' => (int) env('EXTERNAL_POSTS_MEDIA_REQUESTS_PER_MINUTE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics publication discovery
    |--------------------------------------------------------------------------
    |
    | Discovery looks for new posts on every channel every
    | `discovery_interval_hours` (X separately, since X bills each read), and
    | reads back `discovery_overlap_hours` from the newest post it has seen.
    |
    */

    'analytics' => [
        'discovery_interval_hours' => (int) env('PUBLICATION_DISCOVERY_INTERVAL_HOURS', 3),
        'x_discovery_interval_hours' => (int) env('X_PUBLICATION_DISCOVERY_INTERVAL_HOURS', 24),
        'discovery_overlap_hours' => (int) env('PUBLICATION_DISCOVERY_OVERLAP_HOURS', 6),
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

    /*
    |--------------------------------------------------------------------------
    | Favicons
    |--------------------------------------------------------------------------
    |
    | Host of the favicon provider behind the internal `favicon/{domain}`
    | route. The server fetches the icon so browsers never leak the domains
    | they show to a third party.
    |
    */

    'favicon' => [
        'api' => env('FAVICON_API', 'https://icons.duckduckgo.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | RSS Feeds
    |--------------------------------------------------------------------------
    |
    | Create › Feeds. Each feed is refreshed every `poll_interval_minutes`;
    | failures back off exponentially up to `max_backoff_minutes`. The
    | directory is the curated "Explore feeds" list shown in the UI.
    |
    */

    'rss_feeds' => [
        'poll_interval_minutes' => (int) env('RSS_FEEDS_POLL_INTERVAL_MINUTES', 480),
        'max_backoff_minutes' => (int) env('RSS_FEEDS_MAX_BACKOFF_MINUTES', 1440),
        'refresh_cooldown_minutes' => (int) env('RSS_FEEDS_REFRESH_COOLDOWN_MINUTES', 5),
        'max_items_per_feed' => (int) env('RSS_FEEDS_MAX_ITEMS_PER_FEED', 100),
        'max_feeds_per_workspace' => (int) env('RSS_FEEDS_MAX_PER_WORKSPACE', 50),
        'og_image_max_items_per_poll' => (int) env('RSS_FEEDS_OG_IMAGE_MAX_ITEMS', 20),
        'og_image_max_age_days' => (int) env('RSS_FEEDS_OG_IMAGE_MAX_AGE_DAYS', 14),
        'og_image_max_page_bytes' => (int) env('RSS_FEEDS_OG_IMAGE_MAX_PAGE_BYTES', 256 * 1024),
        'max_response_bytes' => (int) env('RSS_FEEDS_MAX_RESPONSE_BYTES', 5 * 1024 * 1024),
        'fetch_budget_seconds' => (int) env('RSS_FEEDS_FETCH_BUDGET_SECONDS', 10),
        'directory' => [
            'favorites' => [
                ['name' => 'Creator Science', 'url' => 'https://creatorscience.com/rss/'],
                ['name' => 'Lindsey Gamble\'s Newsletter', 'url' => 'https://rss.beehiiv.com/feeds/3W8Cmlhot6.xml'],
                ['name' => 'Passionfruit', 'url' => 'https://passionfru.it/feed/'],
                ['name' => 'ICYMI by Lia Haberman', 'url' => 'https://liahaberman.substack.com/feed'],
                ['name' => 'Link in Bio', 'url' => 'https://www.milkkarten.net/feed'],
                ['name' => 'Geekout Newsletter', 'url' => 'https://rss.beehiiv.com/feeds/e2fyt8op5j.xml'],
                ['name' => 'SparkToro Blog', 'url' => 'https://sparktoro.com/blog/feed/'],
            ],
            'tech' => [
                ['name' => 'Engadget', 'url' => 'https://www.engadget.com/rss.xml'],
                ['name' => 'The Verge', 'url' => 'https://www.theverge.com/rss/index.xml'],
                ['name' => 'Wired', 'url' => 'https://www.wired.com/feed/rss'],
                ['name' => 'TechCrunch', 'url' => 'https://techcrunch.com/feed/'],
                ['name' => 'Lifehacker', 'url' => 'https://lifehacker.com/feed/rss'],
                ['name' => 'Gizmodo', 'url' => 'https://gizmodo.com/feed'],
                ['name' => 'Mashable', 'url' => 'https://mashable.com/feeds/rss/all'],
                ['name' => 'Fast Company', 'url' => 'https://feeds.feedburner.com/fastcompany/headlines'],
                ['name' => 'Ars Technica', 'url' => 'https://feeds.arstechnica.com/arstechnica/features'],
                ['name' => 'The Keyword - Google', 'url' => 'https://blog.google/rss/'],
                ['name' => 'MacRumors', 'url' => 'https://feeds.macrumors.com/MacRumors-All'],
                ['name' => 'Android Central', 'url' => 'https://www.androidcentral.com/feed'],
                ['name' => 'TED Talks', 'url' => 'https://feeds.feedburner.com/tedtalks_video'],
                ['name' => 'Slashdot', 'url' => 'https://rss.slashdot.org/Slashdot/slashdotMain'],
                ['name' => 'VentureBeat', 'url' => 'https://feeds.feedburner.com/venturebeat/SZYF'],
                ['name' => 'TheNextWeb', 'url' => 'https://feeds2.feedburner.com/thenextweb'],
                ['name' => 'Hackaday', 'url' => 'https://hackaday.com/blog/feed'],
                ['name' => 'Make Magazine', 'url' => 'https://makezine.com/feed/'],
                ['name' => 'MIT Technology Review', 'url' => 'https://www.technologyreview.com/feed/'],
                ['name' => 'Hacker News', 'url' => 'https://news.ycombinator.com/rss'],
                ['name' => '9to5Mac', 'url' => 'https://9to5mac.com/feed/'],
                ['name' => 'Cnet', 'url' => 'https://www.cnet.com/rss/news/'],
                ['name' => 'Technology - The New York Times', 'url' => 'https://www.nytimes.com/svc/collections/v1/publish/https:/www.nytimes.com/section/technology/rss.xml'],
            ],
            'news' => [
                ['name' => 'BBC News', 'url' => 'https://feeds.bbci.co.uk/news/rss.xml'],
                ['name' => 'The New York Times', 'url' => 'https://rss.nytimes.com/services/xml/rss/nyt/HomePage.xml'],
                ['name' => 'The Guardian', 'url' => 'https://www.theguardian.com/uk/rss'],
                ['name' => 'NPR', 'url' => 'https://feeds.npr.org/1001/rss.xml'],
                ['name' => 'The World This Week | The Economist', 'url' => 'https://www.economist.com/the-world-this-week/rss.xml'],
                ['name' => 'Time', 'url' => 'https://time.com/feed/'],
                ['name' => 'Slate Magazine', 'url' => 'https://slate.com/feeds/all.rss'],
                ['name' => 'The Atlantic', 'url' => 'https://www.theatlantic.com/feed/all/'],
                ['name' => 'Yahoo News', 'url' => 'https://news.yahoo.com/rss'],
                ['name' => 'ABC News', 'url' => 'https://abcnews.go.com/abcnews/topstories'],
                ['name' => 'Culture - The New Yorker', 'url' => 'https://www.newyorker.com/feed/culture'],
                ['name' => 'Vice', 'url' => 'https://www.vice.com/en/feed/'],
                ['name' => 'Vox', 'url' => 'https://www.vox.com/rss/index.xml'],
                ['name' => 'The Washington Post', 'url' => 'https://feeds.washingtonpost.com/rss/world'],
                ['name' => 'Al Jazeera', 'url' => 'https://www.aljazeera.com/xml/rss/all.xml'],
            ],
            'business' => [
                ['name' => 'Business Insider', 'url' => 'https://feeds2.feedburner.com/businessinsider'],
                ['name' => 'Fast Company', 'url' => 'https://feeds.feedburner.com/fastcompany/headlines'],
                ['name' => 'Harvard Business Review', 'url' => 'https://hbr.org/resources/xml/atom/tip.xml'],
                ['name' => 'Seth\'s Blog', 'url' => 'https://seths.blog/feed/atom/'],
                ['name' => 'Entrepreneur', 'url' => 'https://www.entrepreneur.com/rss-feed/latest'],
                ['name' => 'The World This Week | The Economist', 'url' => 'https://www.economist.com/the-world-this-week/rss.xml'],
                ['name' => 'The Hubspot Marketing Blog', 'url' => 'https://blog.hubspot.com/marketing/rss.xml'],
                ['name' => 'Time', 'url' => 'https://time.com/feed/'],
                ['name' => 'VentureBeat', 'url' => 'https://feeds.feedburner.com/venturebeat/SZYF'],
                ['name' => 'Copyblogger', 'url' => 'https://copyblogger.com/feed/'],
                ['name' => 'Inc. Magazine', 'url' => 'https://www.inc.com/rss'],
                ['name' => 'Business - The New York Times', 'url' => 'https://www.nytimes.com/svc/collections/v1/publish/https:/www.nytimes.com/section/business/rss.xml'],
                ['name' => 'Tim Ferris', 'url' => 'https://tim.blog/feed/'],
                ['name' => 'Small Business Trends', 'url' => 'https://feeds.feedburner.com/SmallBusinessTrends'],
            ],
            'art_media' => [
                ['name' => 'Design Milk', 'url' => 'https://design-milk.com/feed/'],
                ['name' => 'Colossal', 'url' => 'https://www.thisiscolossal.com/feed/'],
                ['name' => 'It\'s Nice That', 'url' => 'https://feeds2.feedburner.com/itsnicethat/SlXC'],
                ['name' => 'Booooooom', 'url' => 'https://www.booooooom.com/feed/'],
                ['name' => 'Contemporary Art Daily', 'url' => 'https://www.contemporaryartdaily.com/feed/'],
                ['name' => 'Post Secret', 'url' => 'https://postsecret.com/feed/'],
                ['name' => 'Ignant', 'url' => 'https://www.ignant.com/feed/'],
                ['name' => 'this isn\'t happiness', 'url' => 'https://feeds.feedburner.com/thisisnthappiness'],
                ['name' => 'Hyperallergic', 'url' => 'https://hyperallergic.com/feed/'],
                ['name' => 'ArtsJournal', 'url' => 'https://www.artsjournal.com/feed'],
                ['name' => 'Art and Design - The New York Times', 'url' => 'https://www.nytimes.com/svc/collections/v1/publish/https:/www.nytimes.com/section/arts/design/rss.xml'],
                ['name' => 'ARTNews', 'url' => 'https://www.artnews.com/feed/rss/'],
                ['name' => 'Hi-Fructose Magazine', 'url' => 'https://hifructose.com/feed/'],
                ['name' => 'Artsy', 'url' => 'https://www.artsy.net/rss/news'],
            ],
            'entertainment' => [
                ['name' => 'Buzzfeed', 'url' => 'https://www.buzzfeed.com/index.xml'],
                ['name' => 'Rolling Stone', 'url' => 'https://www.rollingstone.com/feed/rss/'],
                ['name' => 'SlashFilm', 'url' => 'https://feeds.feedburner.com/slashfilm'],
                ['name' => 'IndieWire', 'url' => 'https://www.indiewire.com/feed/rss/'],
                ['name' => 'TMZ.com', 'url' => 'https://www.tmz.com/rss.xml'],
                ['name' => 'Cracked.com', 'url' => 'https://feeds.feedburner.com/CrackedRSS'],
                ['name' => 'Deadline.com', 'url' => 'https://deadline.com/feed/rss/'],
                ['name' => 'Movies - The New York Times', 'url' => 'https://www.nytimes.com/svc/collections/v1/publish/https:/www.nytimes.com/section/movies/rss.xml'],
                ['name' => 'Vulture', 'url' => 'https://feeds.feedburner.com/nymag/vulture'],
                ['name' => 'Variety', 'url' => 'https://variety.com/feed/rss/'],
                ['name' => 'Ain\'t It Cool News', 'url' => 'https://www.aintitcool.com/node/feed/'],
                ['name' => 'Nerdist', 'url' => 'https://nerdist.com/feed/'],
            ],
            'science' => [
                ['name' => 'Wired', 'url' => 'https://www.wired.com/feed/rss'],
                ['name' => 'Scientific American', 'url' => 'https://www.scientificamerican.com/platform/syndication/rss/'],
                ['name' => 'MIT Technology Review', 'url' => 'https://www.technologyreview.com/feed/'],
                ['name' => 'New Scientist', 'url' => 'https://www.newscientist.com/feed/home/'],
                ['name' => 'ScienceDaily', 'url' => 'https://www.sciencedaily.com/rss/all.xml'],
                ['name' => 'Space.com', 'url' => 'https://www.space.com/feeds.xml'],
                ['name' => 'Science - The New York Times', 'url' => 'https://www.nytimes.com/svc/collections/v1/publish/https:/www.nytimes.com/section/science/rss.xml'],
                ['name' => 'Information is Beautiful', 'url' => 'https://feeds.feedburner.com/InformationIsBeautiful'],
                ['name' => 'Futurity', 'url' => 'https://www.futurity.org/feed/'],
                ['name' => 'Phys.org', 'url' => 'https://phys.org/rss-feed/'],
                ['name' => 'Futurism', 'url' => 'https://futurism.com/feed'],
                ['name' => 'Science and Technology | The Economist', 'url' => 'https://www.economist.com/science-and-technology/rss.xml'],
            ],
        ],
    ],

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
            'deep_link' => env('TELEGRAM_DEEP_LINK', 'https://t.me'),
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
