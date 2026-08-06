<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\StripeEventListener;
use App\Models\AccessToken;
use App\Models\Account;
use App\Models\AiUsageLog;
use App\Models\Automation;
use App\Models\AutomationNodeRun;
use App\Models\AutomationNodeState;
use App\Models\AutomationRun;
use App\Models\AutomationTriggerItem;
use App\Models\Invite;
use App\Models\Media;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Plan;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvite;
use App\Models\WorkspaceLabel;
use App\Models\WorkspaceSignature;
use App\Services\PostHogService;
use App\Services\PostTemplate\Registry as PostTemplateRegistry;
use App\Socialite\DiscordProvider;
use App\Socialite\InstagramProvider;
use App\Socialite\LinkedInPageExtendSocialite;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Events\WebhookReceived;
use Laravel\Nightwatch\Facades\Nightwatch;
use Laravel\Nightwatch\Records\CacheEvent;
use Laravel\Passport\Passport;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use PostHog\PostHog;
use SocialiteProviders\Facebook\FacebookExtendSocialite;
use SocialiteProviders\LinkedIn\LinkedInExtendSocialite;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Pinterest\PinterestExtendSocialite;
use SocialiteProviders\TikTok\TikTokExtendSocialite;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PostTemplateRegistry::class);

        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureMorphMap();
        $this->configurePostHog();
        $this->configureRateLimiting();
        $this->configureSocialite();
        $this->configureStripeWebhooks();

        Cashier::useCustomerModel(Account::class);
        Cashier::useSubscriptionModel(Subscription::class);
        Cashier::useSubscriptionItemModel(SubscriptionItem::class);
        Cashier::keepPastDueSubscriptionsActive();

        $this->configurePassport();
    }

    protected function configurePassport(): void
    {
        Passport::useTokenModel(AccessToken::class);

        // API keys may omit an application expiry ("never"). Passport still
        // embeds a JWT `exp`, so keep that far ahead and enforce optional
        // `oauth_access_tokens.expires_at` in LoadWorkspaceFromToken.
        Passport::personalAccessTokensExpireIn(now()->addYears(100));

        Passport::tokensCan([
            'mcp:use' => 'Use MCP server',
        ]);

        Passport::authorizationView('mcp.authorize');
    }

    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'accessToken' => AccessToken::class,
            'account' => Account::class,
            'aiUsageLog' => AiUsageLog::class,
            'automation' => Automation::class,
            'automationNodeRun' => AutomationNodeRun::class,
            'automationNodeState' => AutomationNodeState::class,
            'automationRun' => AutomationRun::class,
            'automationTriggerItem' => AutomationTriggerItem::class,
            'invite' => Invite::class,
            'media' => Media::class,
            'notification' => Notification::class,
            'notificationPreference' => NotificationPreference::class,
            'plan' => Plan::class,
            'post' => Post::class,
            'postComment' => PostComment::class,
            'postPlatform' => PostPlatform::class,
            'socialAccount' => SocialAccount::class,
            'subscription' => Subscription::class,
            'subscriptionItem' => SubscriptionItem::class,
            'user' => User::class,
            'workspace' => Workspace::class,
            'workspaceInvite' => WorkspaceInvite::class,
            'workspaceLabel' => WorkspaceLabel::class,
            'workspaceSignature' => WorkspaceSignature::class,
        ]);
    }

    protected function configurePostHog(): void
    {
        if (! PostHogService::isEnabled()) {
            return;
        }

        PostHog::init(config('services.posthog.api_key'), [
            'host' => config('services.posthog.host'),
        ]);
    }

    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            if ($this->app->environment('local')) {
                return Limit::none();
            }

            return Limit::perMinute(60)->by($request->workspace?->id ?: $request->ip());
        });

        RateLimiter::for(
            'mcp-oauth-registration',
            fn (Request $request): Limit => Limit::perMinute(30)->by($request->ip()),
        );

        // Signed media uploads (api.uploads.store). MCP hosts share egress IPs
        // across tenants — key by workspace_id from the signed URL, with a high
        // IP backstop so one client cannot flood every workspace.
        RateLimiter::for('signed-uploads', function (Request $request) {
            $limits = [
                Limit::perMinute((int) config('trypost.media.signed_upload_per_ip_per_minute'))
                    ->by("ip:{$request->ip()}"),
            ];

            $workspaceId = $request->query('workspace_id');

            if (filled($workspaceId)) {
                array_unshift(
                    $limits,
                    Limit::perMinute((int) config('trypost.media.signed_upload_per_workspace_per_minute'))
                        ->by("workspace:{$workspaceId}"),
                );
            }

            return $limits;
        });
    }

    protected function configureStripeWebhooks(): void
    {
        Event::listen(WebhookReceived::class, StripeEventListener::class);
    }

    protected function configureSocialite(): void
    {
        // Google Auth (login/signup) - separate from YouTube OAuth
        Socialite::extend('google-auth', function ($app) {
            $config = $app['config']['services.google-auth'];

            return Socialite::buildProvider(GoogleProvider::class, $config);
        });

        // Instagram Business Login
        Socialite::extend('instagram', function ($app) {
            $config = $app['config']['services.instagram'];

            return Socialite::buildProvider(InstagramProvider::class, $config);
        });

        Socialite::extend('discord', function ($app) {
            $config = $app['config']['services.discord'];

            return Socialite::buildProvider(DiscordProvider::class, $config);
        });

        Event::listen(SocialiteWasCalled::class, FacebookExtendSocialite::class);
        Event::listen(SocialiteWasCalled::class, LinkedInExtendSocialite::class);
        Event::listen(SocialiteWasCalled::class, LinkedInPageExtendSocialite::class);
        Event::listen(SocialiteWasCalled::class, PinterestExtendSocialite::class);
        Event::listen(SocialiteWasCalled::class, TikTokExtendSocialite::class);
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Disable wrapping of JSON resources
        JsonResource::withoutWrapping();
        Model::shouldBeStrict(! $this->app->isProduction());

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );

        Nightwatch::rejectCacheEvents(function (CacheEvent $cacheEvent) {
            return in_array($cacheEvent->key, [
                'illuminate:foundation:down',
                'illuminate:queue:restart',
                'illuminate:schedule:interrupt',
            ]);
        });

        // Custom email verification template
        VerifyEmail::toMailUsing(function (User $user, string $url) {
            return (new MailMessage)
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->subject('Verify your email address')
                ->view('mail.email-verification', [
                    'title' => 'Verify your email address',
                    'previewText' => 'Please verify your email address.',
                    'user' => $user,
                    'url' => $url,
                ]);
        });

        // Custom password reset template
        ResetPassword::toMailUsing(function (User $user, string $token) {
            $url = url(route('password.reset', [
                'token' => $token,
                'email' => $user->getEmailForPasswordReset(),
            ], false));

            return (new MailMessage)
                ->from(config('mail.from.address'), config('mail.from.name'))
                ->subject('Reset your password')
                ->view('mail.password-reset', [
                    'title' => 'Reset your password',
                    'previewText' => 'Reset your password.',
                    'user' => $user,
                    'url' => $url,
                ]);
        });
    }
}
