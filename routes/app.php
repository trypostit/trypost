<?php

declare(strict_types=1);

use App\Enums\Analytics\ExportFormat;
use App\Http\Controllers\App\ApiKeyController;
use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\CanvaController;
use App\Http\Controllers\App\ChannelController;
use App\Http\Controllers\App\ChannelPostingScheduleController;
use App\Http\Controllers\App\ChannelQueueController;
use App\Http\Controllers\App\DiscordController as AppDiscordController;
use App\Http\Controllers\App\GoogleMediaController;
use App\Http\Controllers\App\GooglePhotosSessionController;
use App\Http\Controllers\App\IdeaController;
use App\Http\Controllers\App\IdeaGenerateController;
use App\Http\Controllers\App\IdeaStageController;
use App\Http\Controllers\App\InsightsController;
use App\Http\Controllers\App\LibraryTemplateController;
use App\Http\Controllers\App\LinkPreviewController;
use App\Http\Controllers\App\LinkPreviewMediaController;
use App\Http\Controllers\App\McpSettingsController;
use App\Http\Controllers\App\MediaAltTextController;
use App\Http\Controllers\App\MediaImportController;
use App\Http\Controllers\App\MediaUploadController;
use App\Http\Controllers\App\PinterestBoardController;
use App\Http\Controllers\App\PostAiAssistantController;
use App\Http\Controllers\App\PostApprovalController;
use App\Http\Controllers\App\PostController;
use App\Http\Controllers\App\PostGroupController;
use App\Http\Controllers\App\PostLabelController;
use App\Http\Controllers\App\PostNoteController;
use App\Http\Controllers\App\PostRecurrenceController;
use App\Http\Controllers\App\PostScheduleController;
use App\Http\Controllers\App\PostTemplateController;
use App\Http\Controllers\App\PostTemplatePickerController;
use App\Http\Controllers\App\RepurposeController;
use App\Http\Controllers\App\RssFeedCollectionController;
use App\Http\Controllers\App\RssFeedController;
use App\Http\Controllers\App\RssFeedItemController;
use App\Http\Controllers\App\Settings\AccountController;
use App\Http\Controllers\App\Settings\AuthenticationController;
use App\Http\Controllers\App\Settings\NotificationPreferenceController;
use App\Http\Controllers\App\Settings\PreferencesController;
use App\Http\Controllers\App\Settings\ProfileController;
use App\Http\Controllers\App\UnsplashController;
use App\Http\Controllers\App\WebhookController;
use App\Http\Controllers\App\WelcomeController;
use App\Http\Controllers\App\WorkspaceController;
use App\Http\Controllers\App\WorkspaceInviteController;
use App\Http\Controllers\App\WorkspaceLabelController;
use App\Http\Controllers\App\WorkspaceSignatureController;
use App\Http\Controllers\Auth\BlueskyController;
use App\Http\Controllers\Auth\DiscordController;
use App\Http\Controllers\Auth\FacebookController;
use App\Http\Controllers\Auth\GoogleBusinessController;
use App\Http\Controllers\Auth\InstagramController;
use App\Http\Controllers\Auth\InstagramFacebookController;
use App\Http\Controllers\Auth\LinkedInController;
use App\Http\Controllers\Auth\MastodonController;
use App\Http\Controllers\Auth\PinterestController;
use App\Http\Controllers\Auth\SocialController;
use App\Http\Controllers\Auth\TelegramController;
use App\Http\Controllers\Auth\ThreadsController;
use App\Http\Controllers\Auth\TikTokController;
use App\Http\Controllers\Auth\XController;
use App\Http\Controllers\Auth\YouTubeController;
use App\Http\Controllers\FaviconController;
use App\Http\Middleware\App\EnsureAccountReady;
use App\Http\Middleware\App\EnsureHasWorkspace;
use App\Support\TemplateLibrary;
use Illuminate\Support\Facades\Route;

// Subscription selection (requires auth but not subscription)
Route::middleware(['auth'])->group(function () {

    Route::get('/', function () {
        return redirect()->route('app.calendar');
    })->name('app.home');

    Route::get('subscribe', [BillingController::class, 'subscribe'])->name('app.subscribe');
    Route::get('welcome', fn () => redirect()->route('app.welcome.persona'))->name('app.welcome');
    Route::get('welcome/persona', [WelcomeController::class, 'persona'])->name('app.welcome.persona');
    Route::post('welcome/persona', [WelcomeController::class, 'storePersona'])->name('app.welcome.persona.store');
    Route::get('welcome/goals', [WelcomeController::class, 'goals'])->name('app.welcome.goals');
    Route::post('welcome/goals', [WelcomeController::class, 'storeGoals'])->name('app.welcome.goals.store');
    Route::get('welcome/referral-source', [WelcomeController::class, 'referralSource'])->name('app.welcome.referral-source');
    Route::post('welcome/referral-source', [WelcomeController::class, 'storeReferralSource'])
        ->middleware('throttle:6,1')
        ->name('app.welcome.referral-source.store');
    Route::get('welcome/connect', [WelcomeController::class, 'connect'])->name('app.welcome.connect');
    Route::post('welcome/connect', [WelcomeController::class, 'storeConnect'])
        ->middleware('throttle:6,1')
        ->name('app.welcome.connect.store');
    Route::get('welcome/plan', [WelcomeController::class, 'plan'])->name('app.welcome.plan');
    Route::post('welcome/plan', [WelcomeController::class, 'storePlan'])
        ->middleware('throttle:6,1')
        ->name('app.welcome.plan.store');
    Route::get('welcome/subscription-required', [WelcomeController::class, 'subscriptionRequired'])->name('app.welcome.subscription-required');
    Route::get('billing/processing', [BillingController::class, 'processing'])->name('app.billing.processing');

    Route::get('workspaces/create', [WorkspaceController::class, 'create'])->name('app.workspaces.create');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('app.workspaces.store');
});

// Social Connect routes
Route::middleware(['auth'])->group(function () {
    // Starting a connection reads the user's current workspace, so these require
    // one — during onboarding they redirect to workspace creation. Disconnecting
    // lives here too (and not behind EnsureAccountReady) so it works before a
    // subscription exists; the controller still authorizes workspace ownership.
    Route::middleware(EnsureHasWorkspace::class)->group(function () {
        Route::get('connect/linkedin', [LinkedInController::class, 'connect'])->name('app.social.linkedin.connect');
        Route::put('channels/{account}/posting-schedule', [ChannelPostingScheduleController::class, 'update'])->name('app.channels.posting-schedule.update');
        Route::post('channels/{account}/posting-schedule/generate', [ChannelPostingScheduleController::class, 'generate'])->name('app.channels.posting-schedule.generate');
        Route::post('channels/{account}/posting-schedule/copy', [ChannelPostingScheduleController::class, 'copy'])->name('app.channels.posting-schedule.copy');
        Route::get('connect/x', [XController::class, 'connect'])->name('app.social.x.connect');
        Route::get('connect/tiktok', [TikTokController::class, 'connect'])->name('app.social.tiktok.connect');
        Route::get('connect/youtube', [YouTubeController::class, 'connect'])->name('app.social.youtube.connect');
        Route::get('connect/facebook', [FacebookController::class, 'connect'])->name('app.social.facebook.connect');
        Route::get('connect/instagram', [InstagramController::class, 'connect'])->name('app.social.instagram.connect');
        Route::get('connect/instagram-facebook', [InstagramFacebookController::class, 'connect'])->name('app.social.instagram-facebook.connect');
        Route::get('connect/threads', [ThreadsController::class, 'connect'])->name('app.social.threads.connect');
        Route::get('connect/pinterest', [PinterestController::class, 'connect'])->name('app.social.pinterest.connect');
        Route::get('connect/bluesky', [BlueskyController::class, 'connect'])->name('app.social.bluesky.connect');
        Route::post('connect/bluesky', [BlueskyController::class, 'store'])->name('app.social.bluesky.store');
        Route::get('connect/mastodon', [MastodonController::class, 'connect'])->name('app.social.mastodon.connect');
        Route::post('connect/mastodon', [MastodonController::class, 'authorizeInstance'])->name('app.social.mastodon.authorize');
        Route::post('connect/telegram', [TelegramController::class, 'connect'])->name('app.social.telegram.connect');
        Route::get('connect/discord', [DiscordController::class, 'connect'])->name('app.social.discord.connect');
        Route::get('connect/google-business', [GoogleBusinessController::class, 'connect'])->name('app.social.google-business.connect');

        Route::delete('channels/{account}', [SocialController::class, 'disconnect'])->name('app.channels.disconnect');
    });

    // OAuth callbacks and identity selection resolve their workspace from the
    // session set when the flow started, then self-close the popup. They run
    // without the current-workspace gate so a momentarily missing current
    // workspace can't HTML-redirect the popup instead of closing it cleanly.
    Route::get('accounts/linkedin/callback', [LinkedInController::class, 'callback'])->name('app.social.linkedin.callback');
    Route::get('accounts/linkedin/select', [LinkedInController::class, 'selectIdentity'])->name('app.social.linkedin.select-identity');
    Route::post('accounts/linkedin/select', [LinkedInController::class, 'select'])->name('app.social.linkedin.select');

    Route::get('accounts/x/callback', [XController::class, 'callback'])->name('app.social.x.callback');

    Route::get('accounts/tiktok/callback', [TikTokController::class, 'callback'])->name('app.social.tiktok.callback');

    Route::get('accounts/youtube/callback', [YouTubeController::class, 'callback'])->name('app.social.youtube.callback');

    Route::get('accounts/facebook/callback', [FacebookController::class, 'callback'])->name('app.social.facebook.callback');
    Route::get('accounts/facebook/select', [FacebookController::class, 'selectPage'])->name('app.social.facebook.select-page');
    Route::post('accounts/facebook/select', [FacebookController::class, 'select'])->name('app.social.facebook.select');

    Route::get('accounts/instagram/callback', [InstagramController::class, 'callback'])->name('app.social.instagram.callback');
    Route::get('accounts/instagram/select', [InstagramController::class, 'selectAccount'])->name('app.social.instagram.select-account');
    Route::post('accounts/instagram/select', [InstagramController::class, 'select'])->name('app.social.instagram.select');

    Route::get('accounts/instagram-facebook/callback', [InstagramFacebookController::class, 'callback'])->name('app.social.instagram-facebook.callback');
    Route::get('accounts/instagram-facebook/select-page', [InstagramFacebookController::class, 'selectPage'])->name('app.social.instagram-facebook.select-page');
    Route::post('accounts/instagram-facebook/select', [InstagramFacebookController::class, 'select'])->name('app.social.instagram-facebook.select');

    Route::get('accounts/threads/callback', [ThreadsController::class, 'callback'])->name('app.social.threads.callback');

    Route::get('accounts/pinterest/callback', [PinterestController::class, 'callback'])->name('app.social.pinterest.callback');

    Route::get('accounts/mastodon/callback', [MastodonController::class, 'callback'])->name('app.social.mastodon.callback');

    Route::get('accounts/discord/callback', [DiscordController::class, 'callback'])->name('app.social.discord.callback');

    Route::get('accounts/google-business/callback', [GoogleBusinessController::class, 'callback'])->name('app.social.google-business.callback');
    Route::get('accounts/google-business/select', [GoogleBusinessController::class, 'selectLocation'])->name('app.social.google-business.select-location');
    Route::post('accounts/google-business/select', [GoogleBusinessController::class, 'select'])->name('app.social.google-business.select');
});

// Routes that require account access and a current workspace
Route::middleware(['auth', EnsureAccountReady::class, EnsureHasWorkspace::class])->group(function () {
    Route::get('favicon/{domain}', FaviconController::class)->where('domain', '.*')->name('app.favicon');

    // Discord — live lookups for the composer (channel picker + mention autocomplete).
    // Throttled because they proxy the shared bot's (rate-limited) Discord API.
    Route::get('discord/accounts/{account}/channels', [AppDiscordController::class, 'channels'])
        ->middleware('throttle:60,1')
        ->name('app.discord.channels');
    Route::get('discord/accounts/{account}/mentions', [AppDiscordController::class, 'mentions'])
        ->middleware('throttle:60,1')
        ->name('app.discord.mentions');

    Route::get('pinterest/accounts/{account}/boards', [PinterestBoardController::class, 'index'])
        ->middleware('throttle:30,1')
        ->name('app.pinterest.boards.index');
    Route::post('pinterest/accounts/{account}/boards', [PinterestBoardController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('app.pinterest.boards.store');

    // Workspaces
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('app.workspaces.index');
    Route::post('workspaces/{workspace}/switch', [WorkspaceController::class, 'switch'])->name('app.workspaces.switch');
    Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy'])->name('app.workspaces.destroy');

    // Workspace settings
    Route::get('settings/workspace', [WorkspaceController::class, 'settings'])->name('app.workspace.settings');
    Route::put('settings/workspace', [WorkspaceController::class, 'updateSettings'])->name('app.workspace.settings.update');
    Route::post('settings/workspace/logo', [WorkspaceController::class, 'uploadLogo'])->name('app.workspace.upload-logo');
    Route::delete('settings/workspace/logo', [WorkspaceController::class, 'deleteLogo'])->name('app.workspace.delete-logo');

    // Channels
    Route::get('settings/workspace/channels', [ChannelController::class, 'index'])->name('app.workspace.channels');
    Route::put('settings/workspace/channels/order', [ChannelController::class, 'reorder'])->name('app.channels.reorder');
    Route::get('channels/{account}/publish', [ChannelController::class, 'publish'])->name('app.channels.publish');
    Route::get('channels/{account}/grid', [ChannelController::class, 'grid'])->name('app.channels.grid');
    Route::get('channels/{account}/calendar/{view?}', [ChannelController::class, 'calendar'])
        ->where('view', 'week|month')
        ->name('app.channels.calendar');
    Route::get('channels/{account}/insights', [ChannelController::class, 'insights'])->name('app.channels.insights');
    Route::get('channels/{account}/settings', [ChannelController::class, 'settings'])->name('app.channels.settings');
    Route::put('channels/{account}/queue/order', [ChannelQueueController::class, 'reorder'])->name('app.channels.queue.order');
    Route::put('channels/{account}/queue/slot', [ChannelQueueController::class, 'moveToSlot'])->name('app.channels.queue.slot');

    // Insights
    Route::get('insights', [InsightsController::class, 'index'])->name('app.insights');
    Route::get('insights/download/{format}', [InsightsController::class, 'download'])
        ->whereIn('format', ExportFormat::values())
        ->name('app.insights.download');

    // Schedule
    Route::get('schedule', [PostController::class, 'index'])->name('app.posts.index');
    Route::get('schedule/calendar/{view?}', [PostController::class, 'calendar'])
        ->where('view', 'week|month')
        ->name('app.calendar');

    // Posts
    Route::get('posts/composer-data', [PostController::class, 'composerData'])->name('app.posts.composer-data');
    Route::get('posts/create', [PostController::class, 'create'])->name('app.posts.create');
    Route::post('posts', [PostController::class, 'store'])->name('app.posts.store');
    Route::get('posts/{post}/edit', [PostController::class, 'edit'])->name('app.posts.edit');
    Route::get('posts/{post}/platforms/{postPlatform}/metrics', [PostController::class, 'platformMetrics'])->name('app.posts.platforms.metrics');
    Route::put('posts/{post}', [PostController::class, 'update'])->name('app.posts.update');
    Route::put('posts/{post}/schedule', [PostScheduleController::class, 'update'])->name('app.posts.schedule.update');
    Route::put('posts/{post}/approve', [PostApprovalController::class, 'approve'])->name('app.posts.approve');
    Route::put('posts/{post}/reject', [PostApprovalController::class, 'reject'])->name('app.posts.reject');
    Route::patch('posts/{post}/labels', [PostLabelController::class, 'update'])->name('app.posts.labels.update');
    Route::patch('posts/{post}/recurrence', [PostRecurrenceController::class, 'update'])->name('app.posts.recurrence.update');
    Route::delete('posts/{post}/recurrence', [PostRecurrenceController::class, 'destroy'])->name('app.posts.recurrence.destroy');
    Route::get('posts/{post}/group', [PostGroupController::class, 'show'])->name('app.posts.group.show');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('app.posts.destroy');
    Route::post('posts/{post}/duplicate', [PostController::class, 'duplicate'])->name('app.posts.duplicate');
    Route::post('posts/link-preview', LinkPreviewController::class)
        ->middleware('throttle:30,1')
        ->name('app.posts.link-preview');
    Route::post('posts/link-preview/media', LinkPreviewMediaController::class)
        ->middleware('throttle:media-imports')
        ->name('app.posts.link-preview-media');

    // Post AI
    Route::post('posts/ai/assist', PostAiAssistantController::class)
        ->middleware('throttle:10,1')
        ->name('app.posts.ai.assist');
    Route::post('posts/ai/alt-text', MediaAltTextController::class)
        ->middleware('throttle:10,1')
        ->name('app.posts.ai.alt-text');

    // Post notes
    Route::get('posts/{post}/notes', [PostNoteController::class, 'index'])->name('app.posts.notes.index');
    Route::post('posts/{post}/notes', [PostNoteController::class, 'store'])->name('app.posts.notes.store');
    Route::put('posts/{post}/notes/{note}', [PostNoteController::class, 'update'])->name('app.posts.notes.update');
    Route::delete('posts/{post}/notes/{note}', [PostNoteController::class, 'destroy'])->name('app.posts.notes.destroy');

    // Members
    Route::get('settings/workspace/members', [WorkspaceInviteController::class, 'index'])->name('app.members');
    Route::post('settings/workspace/members/invites', [WorkspaceInviteController::class, 'store'])->name('app.invites.store');
    Route::delete('settings/workspace/members/invites/{invite}', [WorkspaceInviteController::class, 'destroy'])->name('app.invites.destroy');
    Route::delete('settings/workspace/members/{user}', [WorkspaceInviteController::class, 'removeMember'])->name('app.members.remove');
    Route::put('settings/workspace/members/{user}', [WorkspaceInviteController::class, 'updateMember'])->name('app.members.update');

    // Signatures
    Route::get('settings/workspace/signatures', [WorkspaceSignatureController::class, 'index'])->name('app.signatures.index');
    Route::post('settings/workspace/signatures', [WorkspaceSignatureController::class, 'store'])->name('app.signatures.store');
    Route::put('settings/workspace/signatures/{signature}', [WorkspaceSignatureController::class, 'update'])->name('app.signatures.update');
    Route::delete('settings/workspace/signatures/{signature}', [WorkspaceSignatureController::class, 'destroy'])->name('app.signatures.destroy');

    // Media (temporary uploads)
    Route::post('media/chunked', [MediaUploadController::class, 'storeChunked'])->name('app.media.store-chunked');
    Route::post('media/from-url', [MediaUploadController::class, 'storeFromUrl'])
        ->middleware('throttle:media-imports')
        ->name('app.media.store-from-url');
    Route::get('media/unsplash/search', [UnsplashController::class, 'search'])
        ->middleware('throttle:unsplash')
        ->name('app.media.unsplash.search');
    Route::get('media/unsplash/trending', [UnsplashController::class, 'trending'])
        ->middleware('throttle:unsplash')
        ->name('app.media.unsplash.trending');
    Route::post('media/imports', [MediaImportController::class, 'store'])
        ->middleware('throttle:media-imports')
        ->name('app.media.imports.store');
    Route::get('media/imports/{import}', [MediaImportController::class, 'show'])->whereUuid('import')->name('app.media.imports.show');
    Route::get('media/google-photos/sessions/{session}', [GooglePhotosSessionController::class, 'show'])
        ->where('session', GooglePhotosSessionController::SESSION_ROUTE_PATTERN)
        ->middleware('throttle:google-photos-polling')
        ->name('app.media.google-photos.sessions.show');
    Route::delete('media/google-photos/sessions/{session}', [GooglePhotosSessionController::class, 'destroy'])
        ->where('session', GooglePhotosSessionController::SESSION_ROUTE_PATTERN)
        ->name('app.media.google-photos.sessions.destroy');

    // Media sources > Google Drive and Google Photos (popup)
    Route::get('integrations/google/start', [GoogleMediaController::class, 'start'])
        ->middleware('throttle:media-imports')
        ->name('app.integrations.google.start');
    Route::get('integrations/google/callback', [GoogleMediaController::class, 'callback'])
        ->middleware('throttle:media-imports')
        ->name('app.integrations.google.callback');
    Route::post('integrations/google/returns/{nonce}', [GoogleMediaController::class, 'claim'])
        ->where('nonce', '[A-Za-z0-9_-]{16,64}')
        ->middleware('throttle:60,1')
        ->name('app.integrations.google.returns.claim');

    // Media sources > Canva (popup)
    Route::get('integrations/canva/designs/create', [CanvaController::class, 'createDesign'])
        ->middleware('throttle:media-imports')
        ->name('app.integrations.canva.designs.create');
    Route::get('integrations/canva/designs/edit', [CanvaController::class, 'editDesign'])
        ->middleware('throttle:media-imports')
        ->name('app.integrations.canva.designs.edit');
    Route::get('integrations/canva/callback', [CanvaController::class, 'callback'])->name('app.integrations.canva.callback');
    Route::get('integrations/canva/return', [CanvaController::class, 'return'])->name('app.integrations.canva.return');
    Route::get('integrations/canva/returns/{nonce}', [CanvaController::class, 'showReturn'])
        ->where('nonce', '[A-Za-z0-9_-]{16,64}')
        ->middleware('throttle:60,1')
        ->name('app.integrations.canva.returns.show');

    // Create > Idea stages
    Route::post('create/idea-stages', [IdeaStageController::class, 'store'])->name('app.create.idea-stages.store');
    Route::put('create/idea-stages/order', [IdeaStageController::class, 'reorder'])->name('app.create.idea-stages.reorder');
    Route::put('create/idea-stages/{ideaStage}', [IdeaStageController::class, 'update'])->whereUuid('ideaStage')->name('app.create.idea-stages.update');
    Route::delete('create/idea-stages/{ideaStage}', [IdeaStageController::class, 'destroy'])->whereUuid('ideaStage')->name('app.create.idea-stages.destroy');

    // Create > Ideas
    Route::get('create/ideas', [IdeaController::class, 'index'])->name('app.create.ideas.index');
    Route::get('create/ideas/new', [IdeaController::class, 'create'])->name('app.create.ideas.create');
    Route::post('create/ideas/generate', IdeaGenerateController::class)->middleware('throttle:10,1')->name('app.create.ideas.generate');
    Route::get('create/ideas/{idea}', [IdeaController::class, 'show'])->whereUuid('idea')->name('app.create.ideas.show');
    Route::post('create/ideas', [IdeaController::class, 'store'])->name('app.create.ideas.store');
    Route::delete('create/ideas', [IdeaController::class, 'bulkDestroy'])->name('app.create.ideas.bulk-destroy');
    Route::put('create/ideas/{idea}', [IdeaController::class, 'update'])->whereUuid('idea')->name('app.create.ideas.update');
    Route::delete('create/ideas/{idea}', [IdeaController::class, 'destroy'])->whereUuid('idea')->name('app.create.ideas.destroy');
    Route::post('create/ideas/{idea}/duplicate', [IdeaController::class, 'duplicate'])->whereUuid('idea')->name('app.create.ideas.duplicate');
    Route::put('create/ideas/{idea}/move', [IdeaController::class, 'move'])->whereUuid('idea')->name('app.create.ideas.move');

    // Create > Feeds
    Route::get('create/feeds', [RssFeedController::class, 'index'])->name('app.create.feeds.index');
    Route::get('create/feeds/collections/{rssFeedCollection}', [RssFeedController::class, 'collection'])->whereUuid('rssFeedCollection')->name('app.create.feeds.collections.show');
    Route::get('create/feeds/{rssFeed}', [RssFeedController::class, 'show'])->whereUuid('rssFeed')->name('app.create.feeds.show');
    Route::post('create/feeds', [RssFeedController::class, 'store'])->middleware('throttle:20,1')->name('app.create.feeds.store');
    Route::post('create/feeds/refresh', [RssFeedController::class, 'refresh'])->middleware('throttle:12,1')->name('app.create.feeds.refresh');
    Route::put('create/feeds/{rssFeed}', [RssFeedController::class, 'update'])->whereUuid('rssFeed')->name('app.create.feeds.update');
    Route::delete('create/feeds/{rssFeed}', [RssFeedController::class, 'destroy'])->whereUuid('rssFeed')->name('app.create.feeds.destroy');
    Route::post('create/feed-collections', [RssFeedCollectionController::class, 'store'])->name('app.create.feed-collections.store');
    Route::put('create/feed-collections/{rssFeedCollection}', [RssFeedCollectionController::class, 'update'])->whereUuid('rssFeedCollection')->name('app.create.feed-collections.update');
    Route::delete('create/feed-collections/{rssFeedCollection}', [RssFeedCollectionController::class, 'destroy'])->whereUuid('rssFeedCollection')->name('app.create.feed-collections.destroy');
    Route::post('create/feed-items/{rssFeedItem}/import-image', [RssFeedItemController::class, 'importImage'])->whereUuid('rssFeedItem')->middleware('throttle:30,1')->name('app.create.feed-items.import-image');
    Route::post('create/feed-items/{rssFeedItem}/idea', [RssFeedItemController::class, 'saveAsIdea'])->whereUuid('rssFeedItem')->middleware('throttle:30,1')->name('app.create.feed-items.idea');

    // Create > Templates
    Route::get('create/templates', [PostTemplateController::class, 'index'])->name('app.create.templates.index');
    Route::get('create/templates/picker', PostTemplatePickerController::class)->name('app.create.templates.picker');
    Route::post('create/templates/library/{key}/duplicate', [LibraryTemplateController::class, 'duplicate'])->whereIn('key', TemplateLibrary::keys())->name('app.create.templates.library.duplicate');
    Route::post('create/templates', [PostTemplateController::class, 'store'])->name('app.create.templates.store');
    Route::put('create/templates/{postTemplate}', [PostTemplateController::class, 'update'])->whereUuid('postTemplate')->name('app.create.templates.update');
    Route::delete('create/templates/{postTemplate}', [PostTemplateController::class, 'destroy'])->whereUuid('postTemplate')->name('app.create.templates.destroy');
    Route::post('create/templates/{postTemplate}/duplicate', [PostTemplateController::class, 'duplicate'])->whereUuid('postTemplate')->name('app.create.templates.duplicate');

    // Labels
    Route::get('settings/workspace/labels', [WorkspaceLabelController::class, 'index'])->name('app.labels.index');
    Route::post('settings/workspace/labels', [WorkspaceLabelController::class, 'store'])->name('app.labels.store');
    Route::put('settings/workspace/labels/{label}', [WorkspaceLabelController::class, 'update'])->name('app.labels.update');
    Route::delete('settings/workspace/labels/{label}', [WorkspaceLabelController::class, 'destroy'])->name('app.labels.destroy');

    // API Keys
    Route::get('settings/workspace/api-keys', [ApiKeyController::class, 'index'])->name('app.api-keys.index');
    Route::post('settings/workspace/api-keys', [ApiKeyController::class, 'store'])->name('app.api-keys.store');
    Route::post('settings/workspace/api-keys/{tokenId}/regenerate', [ApiKeyController::class, 'regenerate'])->name('app.api-keys.regenerate');
    Route::delete('settings/workspace/api-keys/{tokenId}', [ApiKeyController::class, 'destroy'])->name('app.api-keys.destroy');

    // MCP
    Route::get('settings/workspace/mcp', [McpSettingsController::class, 'index'])->name('app.mcp.index');
    Route::delete('settings/workspace/mcp/{client}', [McpSettingsController::class, 'disconnect'])->name('app.mcp.disconnect');

    // Repurpose
    Route::get('repurposes', [RepurposeController::class, 'index'])->name('app.repurposes.index');
    Route::post('repurposes', [RepurposeController::class, 'store'])->name('app.repurposes.store');
    Route::get('repurposes/{repurpose}', [RepurposeController::class, 'show'])->name('app.repurposes.show');
    Route::put('repurposes/{repurpose}', [RepurposeController::class, 'update'])->name('app.repurposes.update');
    Route::post('repurposes/{repurpose}/activate', [RepurposeController::class, 'activate'])->name('app.repurposes.activate');
    Route::post('repurposes/{repurpose}/pause', [RepurposeController::class, 'pause'])->name('app.repurposes.pause');
    Route::post('repurposes/{repurpose}/resume', [RepurposeController::class, 'resume'])->name('app.repurposes.resume');
    Route::post('repurposes/{repurpose}/disable', [RepurposeController::class, 'disable'])->name('app.repurposes.disable');
    Route::delete('repurposes/{repurpose}', [RepurposeController::class, 'destroy'])->name('app.repurposes.destroy');

    // Webhooks
    Route::get('settings/workspace/webhooks', [WebhookController::class, 'index'])->name('app.webhooks.index');
    Route::post('settings/workspace/webhooks', [WebhookController::class, 'store'])->name('app.webhooks.store');
    Route::get('settings/workspace/webhooks/{webhook}', [WebhookController::class, 'show'])->name('app.webhooks.show');
    Route::put('settings/workspace/webhooks/{webhook}', [WebhookController::class, 'update'])->name('app.webhooks.update');
    Route::post('settings/workspace/webhooks/{webhook}/send-test', [WebhookController::class, 'sendTest'])->name('app.webhooks.send-test');
    Route::post('settings/workspace/webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret'])->name('app.webhooks.rotate-secret');
    Route::post('settings/workspace/webhooks/{webhook}/logs/{webhookLog}/replay', [WebhookController::class, 'replay'])->name('app.webhooks.replay');
    Route::delete('settings/workspace/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('app.webhooks.destroy');

    // Account Settings
    Route::get('settings/account', [AccountController::class, 'edit'])->name('app.account.edit');
    Route::put('settings/account', [AccountController::class, 'update'])->name('app.account.update');

    // Billing
    Route::get('settings/account/billing', [BillingController::class, 'index'])->name('app.billing.index');
    Route::get('settings/account/billing/portal', [BillingController::class, 'portal'])->name('app.billing.portal');
    Route::post('settings/account/billing/change-plan', [BillingController::class, 'changePlan'])->name('app.billing.change-plan');

});

// Settings (auth required)
Route::middleware(['auth'])->group(function () {

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('app.profile.edit');
    Route::put('settings/profile', [ProfileController::class, 'update'])->name('app.profile.update');
    Route::post('settings/profile/photo', [ProfileController::class, 'uploadPhoto'])->name('app.profile.upload-photo');
    Route::delete('settings/profile/photo', [ProfileController::class, 'deletePhoto'])->name('app.profile.delete-photo');
    Route::put('settings/language', [ProfileController::class, 'updateLanguage'])->name('app.profile.language');
    Route::get('settings/preferences', [PreferencesController::class, 'edit'])->name('app.settings.preferences');
    Route::patch('settings/preferences', [PreferencesController::class, 'update'])->name('app.settings.preferences.update');
});

Route::middleware(['auth'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('app.profile.destroy');

    Route::get('settings/authentication', [AuthenticationController::class, 'edit'])->name('app.authentication.edit');
    Route::put('settings/authentication/password', [AuthenticationController::class, 'updatePassword'])
        ->middleware('throttle:6,1')
        ->name('app.authentication.update-password');
    Route::delete('settings/authentication/sessions', [AuthenticationController::class, 'destroyOtherSessions'])
        ->name('app.authentication.destroy-other-sessions');
    Route::get('settings/authentication/providers/{provider}/connect', [AuthenticationController::class, 'connectProvider'])
        ->name('app.authentication.connect-provider');
    Route::delete('settings/authentication/providers/{provider}', [AuthenticationController::class, 'disconnectProvider'])
        ->name('app.authentication.disconnect-provider');

    Route::get('settings/profile/notifications', [NotificationPreferenceController::class, 'edit'])->name('app.notifications.preferences');
    Route::patch('settings/profile/notifications', [NotificationPreferenceController::class, 'update'])->name('app.notifications.preferences.update');
});
