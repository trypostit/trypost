<?php

declare(strict_types=1);

namespace App\Console\Commands\Mail;

use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Enums\UserWorkspace\Role;
use App\Mail\AccountDisconnected;
use App\Mail\MentionedInComment;
use App\Mail\PostAtRisk;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Mail\WebhookPausedMail;
use App\Mail\WorkspaceConnectionsDisconnected;
use App\Mail\WorkspaceInvite;
use App\Models\Account;
use App\Models\Invite;
use App\Models\Post;
use App\Models\PostComment;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\Workspace;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one of every email to a single address so the rendered result — layout,
 * copy and translations — can be eyeballed in a local mail catcher.
 *
 * The sample records are created inside a transaction that is always rolled
 * back: three of the Mailables query their relations at render time, so the rows
 * have to exist while the message is built, but nothing is left behind.
 */
class PreviewEmails extends Command
{
    protected $signature = 'mail:preview
        {email : The address every email is sent to}
        {--locale= : Locale to render in (defaults to the application default)}
        {--only=* : Template slugs to send instead of all of them (e.g. --only=password-reset)}';

    protected $description = 'Send a sample of every application email to one address';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $locale = Locale::tryFrom((string) $this->option('locale')) ?? Locale::DEFAULT;

        $this->components->info("Sending to {$email} in {$locale->value} ({$locale->label()})");

        DB::beginTransaction();

        try {
            $sent = $this->sendAll($email, $locale);
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } finally {
            DB::rollBack();
        }

        $this->newLine();
        $this->components->info("{$sent} emails sent. Sample records were rolled back.");

        return self::SUCCESS;
    }

    /**
     * The slugs the run is limited to, or an empty array for every template.
     *
     * @return array<int, string>
     */
    private function only(): array
    {
        return array_values(array_filter((array) $this->option('only')));
    }

    private function wants(string $slug): bool
    {
        return $this->only() === [] || in_array($slug, $this->only(), true);
    }

    private function sendAll(string $email, Locale $locale): int
    {
        [$user, $workspace] = $this->sampleWorkspace($locale);

        $linkedIn = $this->sampleAccount($workspace, Platform::LinkedIn);
        $instagram = $this->sampleAccount($workspace, Platform::Instagram);

        $publishedPost = $this->samplePost($workspace, $user, $linkedIn, Status::Published);
        $failedPost = $this->samplePost($workspace, $user, $instagram, Status::Failed);
        $scheduledPost = $this->samplePost($workspace, $user, $linkedIn, Status::Pending);

        $webhook = Webhook::factory()->create([
            'workspace_id' => $workspace->id,
            'endpoint' => 'https://example.com/hooks/trypost',
        ]);

        $invite = Invite::factory()->create([
            'account_id' => $workspace->account_id,
            'invited_by' => $user->id,
            'email' => $email,
            'role' => Role::Member,
            'workspaces' => [$workspace->id],
        ]);

        $comment = PostComment::factory()->create([
            'post_id' => $publishedPost->id,
            'user_id' => $user->id,
        ]);

        $mailables = [
            'account-disconnected' => new AccountDisconnected($linkedIn),
            'post-published' => new PostPublished($publishedPost),
            'post-publish-failed' => new PostPublishFailed($failedPost),
            'post-at-risk' => new PostAtRisk(
                $workspace,
                $scheduledPost->postPlatforms()->pluck('id')->all(),
                1,
            ),
            'webhook-paused' => new WebhookPausedMail($webhook),
            'workspace-invite' => new WorkspaceInvite($invite),
            'workspace-connections-disconnected' => new WorkspaceConnectionsDisconnected(
                $workspace,
                collect([$linkedIn, $instagram]),
            ),
            'mentioned-in-comment' => new MentionedInComment(
                $comment,
                $user,
                'Great work on this one — can we push it to Friday?',
            ),
        ];

        $sent = 0;

        foreach ($mailables as $slug => $mailable) {
            if (! $this->wants($slug)) {
                continue;
            }

            $this->send($email, $locale, $slug, $mailable);
            $sent++;
        }

        // These two are notifications rather than Mailables, so their message is
        // built by hand and handed to the mailer with the locale applied.
        $notifications = [
            'email-verification' => new VerifyEmail,
            'password-reset' => new ResetPassword('preview-token'),
        ];

        foreach ($notifications as $slug => $notification) {
            if (! $this->wants($slug)) {
                continue;
            }

            // The view is rendered inside the locale too, not just built there:
            // `toMail()` only resolves the subject, and `render()` picks up
            // whatever locale is active when it runs.
            [$subject, $html] = $this->inLocale($locale, function () use ($notification, $user): array {
                $mail = $notification->toMail($user);

                return [(string) $mail->subject, (string) $mail->render()];
            });

            Mail::html($html, function (Message $outgoing) use ($email, $subject): void {
                $outgoing->to($email)->subject($subject);
            });

            $this->components->twoColumnDetail($slug, '<fg=green>sent</>');
            $sent++;
        }

        return $sent;
    }

    /**
     * Render inside the given locale the way the notification sender does, so a
     * hand-built message comes out translated like a real one.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $render
     * @return TReturn
     */
    private function inLocale(Locale $locale, callable $render): mixed
    {
        $original = App::getLocale();

        App::setLocale($locale->value);

        try {
            return $render();
        } finally {
            App::setLocale($original);
        }
    }

    private function send(string $email, Locale $locale, string $slug, Mailable $mailable): void
    {
        Mail::to($email)->locale($locale->value)->sendNow($mailable);

        $this->components->twoColumnDetail($slug, '<fg=green>sent</>');
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function sampleWorkspace(Locale $locale): array
    {
        $account = Account::factory()->create(['name' => 'Preview Account']);
        $user = User::factory()->create([
            'account_id' => $account->id,
            'name' => 'Paulo',
            'locale' => $locale,
        ]);
        $account->update(['owner_id' => $user->id]);

        $workspace = Workspace::factory()->create([
            'account_id' => $account->id,
            'user_id' => $user->id,
            'name' => 'Preview Workspace',
        ]);
        $workspace->members()->attach($user->id, ['role' => Role::Admin->value]);
        $user->update(['current_workspace_id' => $workspace->id]);

        return [$user, $workspace];
    }

    private function sampleAccount(Workspace $workspace, Platform $platform): SocialAccount
    {
        return SocialAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'platform' => $platform,
        ]);
    }

    private function samplePost(
        Workspace $workspace,
        User $user,
        SocialAccount $account,
        Status $status,
    ): Post {
        $post = Post::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'scheduled_at' => now()->addHour(),
        ]);

        PostPlatform::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform' => $account->platform,
            'status' => $status,
            'enabled' => true,
            'platform_url' => $status === Status::Published ? 'https://example.com/p/123' : null,
            'error_message' => $status === Status::Failed ? 'The access token has expired.' : null,
        ]);

        return $post->refresh();
    }
}
