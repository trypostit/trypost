<?php

declare(strict_types=1);

use App\Broadcasting\AutomationChannel;
use App\Broadcasting\PostChannel;
use App\Broadcasting\UserAiCreationChannel;
use App\Broadcasting\UserAiGenerationChannel;
use App\Broadcasting\UserAiMediaRegenerationChannel;
use App\Broadcasting\WebhookLogChannel;
use App\Broadcasting\WorkspaceChannel;
use App\Broadcasting\WorkspaceUserChannel;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('post.{post}', PostChannel::class);

Broadcast::channel('automation.{automation}', AutomationChannel::class);

Broadcast::channel('webhook.{webhook}.logs', WebhookLogChannel::class);

Broadcast::channel('workspace.{workspace}', WorkspaceChannel::class);

Broadcast::channel('workspace.{workspace}.user.{owner}', WorkspaceUserChannel::class);

Broadcast::channel('user.{owner}.ai-gen.{generationId}', UserAiGenerationChannel::class);

Broadcast::channel('user.{owner}.ai-creation.{creationId}', UserAiCreationChannel::class);

Broadcast::channel('user.{owner}.ai-media.{regenerationId}', UserAiMediaRegenerationChannel::class);
