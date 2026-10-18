<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AnalyticsController;
use App\Http\Controllers\Api\ApiKeyController;
use App\Http\Controllers\Api\ChannelInsightsController;
use App\Http\Controllers\Api\ChannelPostingScheduleController;
use App\Http\Controllers\Api\ChannelQueueController;
use App\Http\Controllers\Api\DiscordController;
use App\Http\Controllers\Api\IdeaController;
use App\Http\Controllers\Api\IdeaStageController;
use App\Http\Controllers\Api\InboxChannelSyncController;
use App\Http\Controllers\Api\InboxConversationController;
use App\Http\Controllers\Api\InboxConversationMessageController;
use App\Http\Controllers\Api\InboxMessageController;
use App\Http\Controllers\Api\InboxReplyController;
use App\Http\Controllers\Api\InboxResolutionController;
use App\Http\Controllers\Api\InboxScoreController;
use App\Http\Controllers\Api\InstagramAudioController;
use App\Http\Controllers\Api\LabelController;
use App\Http\Controllers\Api\PinterestBoardController;
use App\Http\Controllers\Api\PlatformController;
use App\Http\Controllers\Api\PostApprovalController;
use App\Http\Controllers\Api\PostController;
use App\Http\Controllers\Api\PostNoteController;
use App\Http\Controllers\Api\PostRecurrenceController;
use App\Http\Controllers\Api\RepurposeController;
use App\Http\Controllers\Api\SavedReplyController;
use App\Http\Controllers\Api\SignatureController;
use App\Http\Controllers\Api\SocialAccountController;
use App\Http\Controllers\Api\TikTokCreatorInfoController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::post('/uploads/{token}', [UploadController::class, 'store'])
    ->middleware(['signed', 'throttle:signed-uploads'])
    ->whereUuid('token')
    ->name('api.uploads.store');

Route::middleware(['auth:api', 'workspace.token', 'throttle:api'])->group(function () {
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('api.analytics.index');
    Route::get('/analytics/publications/{publication}', [AnalyticsController::class, 'showPublication'])
        ->whereUuid('publication')
        ->name('api.analytics.publications.show');

    Route::post('/uploads', [UploadController::class, 'create'])->name('api.uploads.create');

    Route::get('/posts', [PostController::class, 'index'])->name('api.posts.index');
    Route::post('/posts/batch', [PostController::class, 'storeBatch'])->name('api.posts.batch.store');
    Route::post('/posts', [PostController::class, 'store'])->name('api.posts.store');
    Route::get('/posts/{post}', [PostController::class, 'show'])->name('api.posts.show');
    Route::put('/posts/{post}', [PostController::class, 'update'])->name('api.posts.update');
    Route::delete('/posts/{post}', [PostController::class, 'destroy'])->name('api.posts.destroy');
    Route::patch('/posts/{post}/recurrence', [PostRecurrenceController::class, 'update'])->name('api.posts.recurrence.update');
    Route::delete('/posts/{post}/recurrence', [PostRecurrenceController::class, 'destroy'])->name('api.posts.recurrence.destroy');
    Route::post('/posts/{post}/media', [PostController::class, 'storeMedia'])->name('api.posts.store-media');
    Route::post('/posts/{post}/media/from-upload', [PostController::class, 'attachMediaFromUpload'])->name('api.posts.attach-media-from-upload');
    Route::post('/posts/{post}/media/from-url', [PostController::class, 'attachMediaFromUrl'])->name('api.posts.attach-media-from-url');
    Route::get('/posts/{post}/metrics', [PostController::class, 'metrics'])->name('api.posts.metrics');
    Route::get('/posts/{post}/preview', [PostController::class, 'preview'])->name('api.posts.preview');
    Route::get('/posts/{post}/notes', [PostNoteController::class, 'index'])->name('api.posts.notes.index');
    Route::post('/posts/{post}/notes', [PostNoteController::class, 'store'])->name('api.posts.notes.store');
    Route::put('/posts/{post}/notes/{note}', [PostNoteController::class, 'update'])->name('api.posts.notes.update');
    Route::delete('/posts/{post}/notes/{note}', [PostNoteController::class, 'destroy'])->name('api.posts.notes.destroy');
    Route::post('/posts/{post}/approve', [PostApprovalController::class, 'approve'])->name('api.posts.approve');
    Route::post('/posts/{post}/reject', [PostApprovalController::class, 'reject'])->name('api.posts.reject');

    Route::get('/content-types', [PlatformController::class, 'contentTypes'])->name('api.content-types');

    Route::get('/workspace', [WorkspaceController::class, 'show'])->name('api.workspace.show');

    Route::get('/signatures', [SignatureController::class, 'index'])->name('api.signatures.index');
    Route::post('/signatures', [SignatureController::class, 'store'])->name('api.signatures.store');
    Route::put('/signatures/{signature}', [SignatureController::class, 'update'])->name('api.signatures.update');
    Route::delete('/signatures/{signature}', [SignatureController::class, 'destroy'])->name('api.signatures.destroy');

    Route::get('/saved-replies', [SavedReplyController::class, 'index'])->name('api.saved-replies.index');
    Route::post('/saved-replies', [SavedReplyController::class, 'store'])->name('api.saved-replies.store');
    Route::put('/saved-replies/{savedReply}', [SavedReplyController::class, 'update'])->whereUuid('savedReply')->name('api.saved-replies.update');
    Route::delete('/saved-replies/{savedReply}', [SavedReplyController::class, 'destroy'])->whereUuid('savedReply')->name('api.saved-replies.destroy');

    Route::get('/ideas', [IdeaController::class, 'index'])->name('api.ideas.index');
    Route::post('/ideas', [IdeaController::class, 'store'])->name('api.ideas.store');
    Route::delete('/ideas', [IdeaController::class, 'bulkDestroy'])->name('api.ideas.bulk-destroy');
    Route::get('/ideas/{idea}', [IdeaController::class, 'show'])->whereUuid('idea')->name('api.ideas.show');
    Route::put('/ideas/{idea}', [IdeaController::class, 'update'])->whereUuid('idea')->name('api.ideas.update');
    Route::delete('/ideas/{idea}', [IdeaController::class, 'destroy'])->whereUuid('idea')->name('api.ideas.destroy');
    Route::post('/ideas/{idea}/duplicate', [IdeaController::class, 'duplicate'])->whereUuid('idea')->name('api.ideas.duplicate');
    Route::put('/ideas/{idea}/move', [IdeaController::class, 'move'])->whereUuid('idea')->name('api.ideas.move');

    Route::get('/idea-stages', [IdeaStageController::class, 'index'])->name('api.idea-stages.index');
    Route::post('/idea-stages', [IdeaStageController::class, 'store'])->name('api.idea-stages.store');
    Route::put('/idea-stages/order', [IdeaStageController::class, 'reorder'])->name('api.idea-stages.reorder');
    Route::put('/idea-stages/{ideaStage}', [IdeaStageController::class, 'update'])->whereUuid('ideaStage')->name('api.idea-stages.update');
    Route::delete('/idea-stages/{ideaStage}', [IdeaStageController::class, 'destroy'])->whereUuid('ideaStage')->name('api.idea-stages.destroy');

    Route::get('/labels', [LabelController::class, 'index'])->name('api.labels.index');
    Route::post('/labels', [LabelController::class, 'store'])->name('api.labels.store');
    Route::put('/labels/{label}', [LabelController::class, 'update'])->name('api.labels.update');
    Route::delete('/labels/{label}', [LabelController::class, 'destroy'])->name('api.labels.destroy');

    Route::get('/inbox/conversations', [InboxConversationController::class, 'index'])->name('api.inbox.conversations.index');
    Route::get('/inbox/conversations/{conversation}', [InboxConversationController::class, 'show'])->whereUuid('conversation')->name('api.inbox.conversations.show');
    Route::post('/inbox/conversations/{conversation}/messages', [InboxConversationMessageController::class, 'store'])->whereUuid('conversation')->middleware('throttle:inbox-send')->name('api.inbox.conversations.messages.store');
    Route::get('/inbox/messages', [InboxMessageController::class, 'index'])->name('api.inbox.messages.index');
    Route::post('/inbox/messages/{message}/replies', [InboxReplyController::class, 'store'])->whereUuid('message')->middleware('throttle:inbox-send')->name('api.inbox.replies.store');
    Route::post('/inbox/messages/{message}/retry', [InboxReplyController::class, 'retry'])->whereUuid('message')->middleware('throttle:inbox-send')->name('api.inbox.replies.retry');
    Route::post('/inbox/resolutions', [InboxResolutionController::class, 'store'])->name('api.inbox.resolutions.store');
    Route::delete('/inbox/messages/{message}/resolution', [InboxResolutionController::class, 'destroy'])->whereUuid('message')->name('api.inbox.resolutions.destroy');
    Route::get('/inbox/score', [InboxScoreController::class, 'show'])->name('api.inbox.score.show');
    Route::post('/inbox/channels/{socialAccount}/sync', [InboxChannelSyncController::class, 'store'])->whereUuid('socialAccount')->middleware('throttle:inbox-sync')->name('api.inbox.channel-sync.store');

    Route::get('/social-accounts', [SocialAccountController::class, 'index'])->name('api.social-accounts.index');
    Route::get('/social-accounts/{account}/boards', [PinterestBoardController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('api.social-accounts.boards');
    Route::post('/social-accounts/{account}/boards', [PinterestBoardController::class, 'store'])
        ->middleware('throttle:60,1')
        ->name('api.social-accounts.boards.store');
    Route::get('/social-accounts/{account}/tiktok-creator-info', [TikTokCreatorInfoController::class, 'show'])
        ->middleware('throttle:60,1')
        ->name('api.social-accounts.tiktok-creator-info');
    Route::get('/social-accounts/{account}/instagram-audio', [InstagramAudioController::class, 'index'])
        ->middleware('throttle:60,1')
        ->name('api.social-accounts.instagram-audio');
    Route::get('/social-accounts/{account}/instagram-audio/{audioId}', [InstagramAudioController::class, 'show'])
        ->whereNumber('audioId')
        ->middleware('throttle:60,1')
        ->name('api.social-accounts.instagram-audio.show');
    Route::get('/social-accounts/{account}/channels', [DiscordController::class, 'channels'])
        ->middleware('throttle:60,1')
        ->name('api.social-accounts.channels');

    Route::get('/channels/{account}/insights', [ChannelInsightsController::class, 'show'])
        ->whereUuid('account')
        ->name('api.channels.insights.show');
    Route::get('/channels/{account}/insights/publications', [ChannelInsightsController::class, 'publications'])
        ->whereUuid('account')
        ->name('api.channels.insights.publications');
    Route::get('/channels/{account}/posting-schedule', [ChannelPostingScheduleController::class, 'show'])
        ->whereUuid('account')
        ->name('api.channels.posting-schedule.show');
    Route::put('/channels/{account}/posting-schedule', [ChannelPostingScheduleController::class, 'update'])
        ->whereUuid('account')
        ->name('api.channels.posting-schedule.update');
    Route::post('/channels/{account}/posting-schedule/generate', [ChannelPostingScheduleController::class, 'generate'])
        ->whereUuid('account')
        ->name('api.channels.posting-schedule.generate');
    Route::post('/channels/{account}/posting-schedule/copy', [ChannelPostingScheduleController::class, 'copy'])
        ->whereUuid('account')
        ->name('api.channels.posting-schedule.copy');
    Route::get('/channels/{account}/queue/slots', [ChannelQueueController::class, 'slots'])
        ->whereUuid('account')
        ->name('api.channels.queue.slots');
    Route::put('/channels/{account}/queue/order', [ChannelQueueController::class, 'reorder'])
        ->whereUuid('account')
        ->name('api.channels.queue.order');
    Route::put('/channels/{account}/queue/slot', [ChannelQueueController::class, 'moveToSlot'])
        ->whereUuid('account')
        ->name('api.channels.queue.slot');

    Route::get('/repurpose-source-formats', [RepurposeController::class, 'sourceFormats'])->name('api.repurpose-source-formats.index');
    Route::get('/repurposes', [RepurposeController::class, 'index'])->name('api.repurposes.index');
    Route::post('/repurposes', [RepurposeController::class, 'store'])->name('api.repurposes.store');
    Route::get('/repurposes/{repurpose}', [RepurposeController::class, 'show'])->name('api.repurposes.show');
    Route::put('/repurposes/{repurpose}', [RepurposeController::class, 'update'])->name('api.repurposes.update');
    Route::get('/repurposes/{repurpose}/items', [RepurposeController::class, 'items'])->name('api.repurposes.items');
    Route::post('/repurposes/{repurpose}/activate', [RepurposeController::class, 'activate'])->name('api.repurposes.activate');
    Route::post('/repurposes/{repurpose}/pause', [RepurposeController::class, 'pause'])->name('api.repurposes.pause');
    Route::post('/repurposes/{repurpose}/resume', [RepurposeController::class, 'resume'])->name('api.repurposes.resume');
    Route::post('/repurposes/{repurpose}/disable', [RepurposeController::class, 'disable'])->name('api.repurposes.disable');
    Route::delete('/repurposes/{repurpose}', [RepurposeController::class, 'destroy'])->name('api.repurposes.destroy');

    Route::get('/webhooks', [WebhookController::class, 'index'])->name('api.webhooks.index');
    Route::post('/webhooks', [WebhookController::class, 'store'])->name('api.webhooks.store');
    Route::get('/webhooks/{webhook}', [WebhookController::class, 'show'])->name('api.webhooks.show');
    Route::put('/webhooks/{webhook}', [WebhookController::class, 'update'])->name('api.webhooks.update');
    Route::post('/webhooks/{webhook}/send-test', [WebhookController::class, 'sendTest'])->name('api.webhooks.send-test');
    Route::post('/webhooks/{webhook}/rotate-secret', [WebhookController::class, 'rotateSecret'])->name('api.webhooks.rotate-secret');
    Route::get('/webhooks/{webhook}/logs', [WebhookController::class, 'logs'])->name('api.webhooks.logs');
    Route::post('/webhooks/{webhook}/logs/{webhookLog}/replay', [WebhookController::class, 'replay'])->name('api.webhooks.replay');
    Route::delete('/webhooks/{webhook}', [WebhookController::class, 'destroy'])->name('api.webhooks.destroy');

    Route::get('/api-keys', [ApiKeyController::class, 'index'])->name('api.api-keys.index');
    Route::post('/api-keys', [ApiKeyController::class, 'store'])->name('api.api-keys.store');
    Route::delete('/api-keys/{apiToken}', [ApiKeyController::class, 'destroy'])->name('api.api-keys.destroy');
});
