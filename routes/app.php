<?php

declare(strict_types=1);

use App\Http\Controllers\App\AnalyticsController;
use App\Http\Controllers\App\ApiKeyController;
use App\Http\Controllers\App\AssetController;
use App\Http\Controllers\App\AutomationController;
use App\Http\Controllers\App\BillingController;
use App\Http\Controllers\App\DiscordController as AppDiscordController;
use App\Http\Controllers\App\GiphyController;
use App\Http\Controllers\App\LinkPreviewController;
use App\Http\Controllers\App\NotificationController;
use App\Http\Controllers\App\OnboardingController;
use App\Http\Controllers\App\PostAiCreateController;
use App\Http\Controllers\App\PostAiGenerateController;
use App\Http\Controllers\App\PostAiRegenerateMediaController;
use App\Http\Controllers\App\PostAiReviewController;
use App\Http\Controllers\App\PostCommentController;
use App\Http\Controllers\App\PostController;
use App\Http\Controllers\App\PostTemplateController;
use App\Http\Controllers\App\PresenceController;
use App\Http\Controllers\App\Settings\AccountController;
use App\Http\Controllers\App\Settings\AuthenticationController;
use App\Http\Controllers\App\Settings\NotificationPreferenceController;
use App\Http\Controllers\App\Settings\ProfileController;
use App\Http\Controllers\App\Settings\SettingsController;
use App\Http\Controllers\App\Settings\UsageController;
use App\Http\Controllers\App\UnsplashController;
use App\Http\Controllers\App\WorkspaceController;
use App\Http\Controllers\App\WorkspaceInviteController;
use App\Http\Controllers\App\WorkspaceLabelController;
use App\Http\Controllers\App\WorkspaceSignatureController;
use App\Http\Controllers\Auth\BlueskyController;
use App\Http\Controllers\Auth\DiscordController;
use App\Http\Controllers\Auth\FacebookController;
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
use App\Http\Middleware\App\EnsureAccountReady;
use App\Http\Middleware\App\EnsureHasWorkspace;
use Illuminate\Support\Facades\Route;

// Subscription selection (requires auth but not subscription)
Route::middleware(['auth'])->group(function () {

    Route::get('/', function () {
        return redirect()->route('app.calendar');
    })->name('app.home');

    Route::get('subscribe', [BillingController::class, 'subscribe'])->name('app.subscribe');
    Route::get('onboarding', [OnboardingController::class, 'index'])->name('app.onboarding');
    Route::post('onboarding', [OnboardingController::class, 'store'])->name('app.onboarding.store');
    Route::get('onboarding/goals', [OnboardingController::class, 'goals'])->name('app.onboarding.goals');
    Route::post('onboarding/goals', [OnboardingController::class, 'storeGoals'])->name('app.onboarding.goals.store');
    Route::get('onboarding/referral-source', [OnboardingController::class, 'referralSource'])->name('app.onboarding.referral-source');
    Route::post('onboarding/referral-source', [OnboardingController::class, 'storeReferralSource'])->name('app.onboarding.referral-source.store');
    Route::get('onboarding/connect', [OnboardingController::class, 'connect'])->name('app.onboarding.connect');
    Route::post('onboarding/connect', [OnboardingController::class, 'checkout'])->name('app.onboarding.checkout');
    Route::get('billing/processing', [BillingController::class, 'processing'])->name('app.billing.processing');

    Route::get('workspaces/create', [WorkspaceController::class, 'create'])->name('app.workspaces.create');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('app.workspaces.store');
    Route::post('workspaces/autofill', [WorkspaceController::class, 'autofillBrand'])
        ->middleware('throttle:10,1')
        ->name('app.workspaces.autofill');

    Route::get('workspace/members/search', [WorkspaceController::class, 'searchMembers'])
        ->middleware('throttle:60,1')
        ->name('app.workspace.members.search');

    Route::post('presence/heartbeat', [PresenceController::class, 'heartbeat'])
        ->name('app.presence.heartbeat');
});

// Social Connect routes
Route::middleware(['auth'])->group(function () {
    // Starting a connection reads the user's current workspace, so these require
    // one — during onboarding they redirect to workspace creation. Disconnecting
    // lives here too (and not behind EnsureAccountReady) so it works before a
    // subscription exists; the controller still authorizes workspace ownership.
    Route::middleware(EnsureHasWorkspace::class)->group(function () {
        Route::get('connect/linkedin', [LinkedInController::class, 'connect'])->name('app.social.linkedin.connect');
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

        Route::delete('accounts/{account}', [SocialController::class, 'disconnect'])->name('app.accounts.disconnect');
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
    Route::get('accounts/youtube/select', [YouTubeController::class, 'selectChannel'])->name('app.social.youtube.select-channel');
    Route::post('accounts/youtube/select', [YouTubeController::class, 'select'])->name('app.social.youtube.select');

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
});

// Routes that require active subscription and completed onboarding
Route::middleware(['auth', EnsureAccountReady::class, EnsureHasWorkspace::class])->group(function () {
    // Discord — live lookups for the composer (channel picker + mention autocomplete).
    // Throttled because they proxy the shared bot's (rate-limited) Discord API.
    Route::get('discord/accounts/{account}/channels', [AppDiscordController::class, 'channels'])
        ->middleware('throttle:60,1')
        ->name('app.discord.channels');
    Route::get('discord/accounts/{account}/mentions', [AppDiscordController::class, 'mentions'])
        ->middleware('throttle:60,1')
        ->name('app.discord.mentions');

    // Workspaces
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('app.workspaces.index');
    Route::post('workspaces/{workspace}/switch', [WorkspaceController::class, 'switch'])->name('app.workspaces.switch');
    Route::delete('workspaces/{workspace}', [WorkspaceController::class, 'destroy'])->name('app.workspaces.destroy');

    // Workspace settings
    Route::get('settings/workspace', [WorkspaceController::class, 'settings'])->name('app.workspace.settings');
    Route::put('settings/workspace', [WorkspaceController::class, 'updateSettings'])->name('app.workspace.settings.update');
    Route::post('settings/workspace/logo', [WorkspaceController::class, 'uploadLogo'])->name('app.workspace.upload-logo');
    Route::delete('settings/workspace/logo', [WorkspaceController::class, 'deleteLogo'])->name('app.workspace.delete-logo');

    // Brand settings
    Route::get('settings/workspace/brand', [WorkspaceController::class, 'brandSettings'])->name('app.workspace.brand');

    // Social Accounts
    Route::get('accounts', [SocialController::class, 'index'])->name('app.accounts');
    Route::put('accounts/{account}/toggle', [SocialController::class, 'toggleActive'])->name('app.accounts.toggle');

    // Analytics
    Route::get('analytics', [AnalyticsController::class, 'index'])->name('app.analytics');
    Route::get('analytics/{account}', [AnalyticsController::class, 'show'])->name('app.analytics.show');

    // Calendar
    Route::get('calendar', [PostController::class, 'calendar'])->name('app.calendar');

    // Posts
    Route::get('posts/{status?}', [PostController::class, 'index'])->name('app.posts.index')->where('status', 'draft|scheduled|published');
    Route::get('posts/create', [PostController::class, 'create'])->name('app.posts.create');
    Route::post('posts', [PostController::class, 'store'])->name('app.posts.store');
    Route::get('posts/{post}/edit', [PostController::class, 'edit'])->name('app.posts.edit');
    Route::get('posts/{post}', [PostController::class, 'show'])->name('app.posts.show');
    Route::get('posts/{post}/platforms/{postPlatform}/metrics', [PostController::class, 'platformMetrics'])->name('app.posts.platforms.metrics');
    Route::put('posts/{post}', [PostController::class, 'update'])->name('app.posts.update');
    Route::delete('posts/{post}', [PostController::class, 'destroy'])->name('app.posts.destroy');
    Route::post('posts/{post}/duplicate', [PostController::class, 'duplicate'])->name('app.posts.duplicate');
    Route::post('posts/link-preview', LinkPreviewController::class)
        ->middleware('throttle:30,1')
        ->name('app.posts.link-preview');

    // Post Templates
    Route::get('post-templates', [PostTemplateController::class, 'index'])->name('app.post-templates.index');
    Route::post('post-templates/{slug}/apply', [PostTemplateController::class, 'apply'])->name('app.post-templates.apply');

    // Post AI
    Route::post('posts/{post}/ai/generate', [PostAiGenerateController::class, 'generate'])->name('app.posts.ai.generate');
    Route::post('posts/{post}/media/{mediaId}/ai/regenerate', [PostAiRegenerateMediaController::class, 'regenerate'])->name('app.posts.ai.regenerate-media');
    Route::post('posts/{post}/ai/review', [PostAiReviewController::class, 'review'])->name('app.posts.ai.review');
    Route::post('posts/ai/create', [PostAiCreateController::class, 'start'])->name('app.posts.ai.create');
    Route::get('posts/ai/{creationId}/loading', [PostAiCreateController::class, 'loading'])->name('app.posts.ai.loading')->whereUuid('creationId');

    // Post Comments
    Route::get('posts/{post}/comments', [PostCommentController::class, 'index'])->name('app.posts.comments.index');
    Route::post('posts/{post}/comments', [PostCommentController::class, 'store'])->name('app.posts.comments.store');
    Route::put('posts/{post}/comments/{comment}', [PostCommentController::class, 'update'])->name('app.posts.comments.update');
    Route::delete('posts/{post}/comments/{comment}', [PostCommentController::class, 'destroy'])->name('app.posts.comments.destroy');
    Route::post('posts/{post}/comments/{comment}/react', [PostCommentController::class, 'react'])->name('app.posts.comments.react');

    // Members
    Route::get('settings/workspace/members', [WorkspaceInviteController::class, 'index'])->name('app.members');
    Route::post('settings/workspace/members/invites', [WorkspaceInviteController::class, 'store'])->name('app.invites.store');
    Route::delete('settings/workspace/members/invites/{invite}', [WorkspaceInviteController::class, 'destroy'])->name('app.invites.destroy');
    Route::delete('settings/workspace/members/{user}', [WorkspaceInviteController::class, 'removeMember'])->name('app.members.remove');
    Route::put('settings/workspace/members/{user}/role', [WorkspaceInviteController::class, 'updateRole'])->name('app.members.update-role');

    // Signatures
    Route::get('signatures', [WorkspaceSignatureController::class, 'index'])->name('app.signatures.index');
    Route::post('signatures', [WorkspaceSignatureController::class, 'store'])->name('app.signatures.store');
    Route::put('signatures/{signature}', [WorkspaceSignatureController::class, 'update'])->name('app.signatures.update');
    Route::delete('signatures/{signature}', [WorkspaceSignatureController::class, 'destroy'])->name('app.signatures.destroy');

    // Assets
    Route::get('assets', [AssetController::class, 'index'])->name('app.assets.index');
    Route::get('assets/search', [AssetController::class, 'search'])->name('app.assets.search');
    Route::post('assets', [AssetController::class, 'store'])->name('app.assets.store');
    Route::post('assets/chunked', [AssetController::class, 'storeChunked'])->name('app.assets.store-chunked');
    Route::post('assets/from-url', [AssetController::class, 'storeFromUrl'])->name('app.assets.store-from-url');
    Route::delete('assets/{media}', [AssetController::class, 'destroy'])->name('app.assets.destroy');
    Route::get('assets/unsplash/search', [UnsplashController::class, 'search'])->name('app.assets.unsplash.search');
    Route::get('assets/unsplash/trending', [UnsplashController::class, 'trending'])->name('app.assets.unsplash.trending');
    Route::get('assets/giphy/search', [GiphyController::class, 'search'])->name('app.assets.giphy.search');
    Route::get('assets/giphy/trending', [GiphyController::class, 'trending'])->name('app.assets.giphy.trending');

    // Labels
    Route::get('labels', [WorkspaceLabelController::class, 'index'])->name('app.labels.index');
    Route::post('labels', [WorkspaceLabelController::class, 'store'])->name('app.labels.store');
    Route::put('labels/{label}', [WorkspaceLabelController::class, 'update'])->name('app.labels.update');
    Route::delete('labels/{label}', [WorkspaceLabelController::class, 'destroy'])->name('app.labels.destroy');

    // Automations
    Route::get('automations', [AutomationController::class, 'index'])->name('app.automations.index');
    Route::post('automations', [AutomationController::class, 'store'])->name('app.automations.store');
    Route::get('automations/{automation}', [AutomationController::class, 'show'])->name('app.automations.show');
    Route::get('automations/{automation}/workflow', [AutomationController::class, 'workflow'])->name('app.automations.workflow');
    Route::get('automations/{automation}/invocations', [AutomationController::class, 'invocations'])->name('app.automations.invocations');
    Route::get('automations/{automation}/metrics', [AutomationController::class, 'metrics'])->name('app.automations.metrics');
    Route::get('automations/{automation}/settings', [AutomationController::class, 'settings'])->name('app.automations.settings');
    Route::put('automations/{automation}', [AutomationController::class, 'update'])->name('app.automations.update');
    Route::delete('automations/{automation}', [AutomationController::class, 'destroy'])->name('app.automations.destroy');
    Route::post('automations/{automation}/activate', [AutomationController::class, 'activate'])->name('app.automations.activate');
    Route::post('automations/{automation}/pause', [AutomationController::class, 'pause'])->name('app.automations.pause');
    Route::post('automations/{automation}/runs/{run}/retry', [AutomationController::class, 'retryRun'])->name('app.automations.runs.retry');
    Route::post('automations/{automation}/test', [AutomationController::class, 'test'])->name('app.automations.test');
    Route::post('automations/{automation}/feed/inspect', [AutomationController::class, 'inspectFeed'])->name('app.automations.feed.inspect');
    Route::get('automations/{automation}/runs/{run}', [AutomationController::class, 'showRun'])->name('app.automations.runs.show');

    // API Keys
    Route::get('settings/workspace/api-keys', [ApiKeyController::class, 'index'])->name('app.api-keys.index');
    Route::post('settings/workspace/api-keys', [ApiKeyController::class, 'store'])->name('app.api-keys.store');
    Route::delete('settings/workspace/api-keys/{tokenId}', [ApiKeyController::class, 'destroy'])->name('app.api-keys.destroy');

    // Account Settings
    Route::get('settings/account', [AccountController::class, 'edit'])->name('app.account.edit');
    Route::put('settings/account', [AccountController::class, 'update'])->name('app.account.update');
    Route::get('settings/account/usage', [UsageController::class, 'index'])->name('app.usage.index');

    // Billing
    Route::get('settings/account/billing', [BillingController::class, 'index'])->name('app.billing.index');
    Route::get('settings/account/billing/portal', [BillingController::class, 'portal'])->name('app.billing.portal');
    Route::post('settings/account/billing/swap-to-yearly', [BillingController::class, 'swapToYearly'])->name('app.billing.swap-to-yearly');

});

// Notifications (auth only, no subscription required)
Route::middleware(['auth'])->group(function () {
    Route::get('notifications', [NotificationController::class, 'index'])->name('app.notifications.index');
    Route::put('notifications/{notification}/read', [NotificationController::class, 'markAsRead'])->name('app.notifications.read');
    Route::post('notifications/read-all', [NotificationController::class, 'markAllAsRead'])->name('app.notifications.read-all');
    Route::post('notifications/archive-all', [NotificationController::class, 'archiveAll'])->name('app.notifications.archive-all');
});

// Settings (auth required)
Route::middleware(['auth'])->group(function () {
    Route::get('settings', [SettingsController::class, 'index'])->name('app.settings');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('app.profile.edit');
    Route::put('settings/profile', [ProfileController::class, 'update'])->name('app.profile.update');
    Route::post('settings/profile/photo', [ProfileController::class, 'uploadPhoto'])->name('app.profile.upload-photo');
    Route::delete('settings/profile/photo', [ProfileController::class, 'deletePhoto'])->name('app.profile.delete-photo');
    Route::put('settings/language', [ProfileController::class, 'updateLanguage'])->name('app.profile.language');
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
    Route::put('settings/profile/notifications', [NotificationPreferenceController::class, 'update'])->name('app.notifications.preferences.update');
});
