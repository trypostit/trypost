<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\UpdatePostRecurrence;
use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\Status as PostStatus;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\PostingSchedule;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator as ValidationValidator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Make a scheduled post repeat: every `interval` days, weeks, months or years, `times` more times. The post must be scheduled with a time, and the last occurrence must fall on or before 2037-12-31 23:59:59 UTC. Each occurrence keeps the same local time in the channel time zone. Only members who publish directly can set a recurrence.')]
class SetPostRecurrenceTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->currentWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $postId = $request->get('post_id');
        $post = is_string($postId) && Str::isUuid($postId)
            ? Post::where('workspace_id', $workspace->id)->find($postId)
            : null;

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $post, 'Post not found.')) {
            return $denied;
        }

        if ($denied = $this->denyUnlessCan($request, 'publishDirectly', $workspace)) {
            return $denied;
        }

        $validated = Validator::make($request->all(), [
            'interval' => ['required', 'integer', 'min:1', 'max:365'],
            'frequency' => ['required', Rule::enum(RecurrenceFrequency::class)],
            'times' => ['required', 'integer', 'min:1', 'max:100'],
        ])->after(function (ValidationValidator $validator) use ($post, $request): void {
            if ($post->status !== PostStatus::Scheduled || $post->scheduled_at === null) {
                $validator->errors()->add('post', __('posts.recurrence.errors.not_scheduled'));

                return;
            }

            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $frequency = RecurrenceFrequency::from((string) $request->get('frequency'));
            $steps = (int) $request->get('interval') * (int) $request->get('times');
            $ceiling = CarbonImmutable::parse(PostingSchedule::MAX_INSTANT, 'UTC');
            $tooFar = $post->hasDestination() && $frequency->advance(
                $post->scheduled_at->toImmutable()->setTimezone(Timezone::normalize($post->socialAccount?->timezone)),
                $steps,
            )->greaterThan($ceiling);

            if ($tooFar) {
                $validator->errors()->add('times', __('posts.recurrence.errors.too_far'));
            }
        })->validate();

        UpdatePostRecurrence::execute($post, $validated);

        $post = Post::query()->with(['socialAccount', 'labels'])->findOrFail($post->id);

        return Response::structured((new PostResource($post))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of a scheduled post.'),
            'interval' => $schema->integer()->required()->description('Repeat every this many units (1-365).'),
            'frequency' => $schema->string()->enum(array_map(fn (RecurrenceFrequency $frequency) => $frequency->value, RecurrenceFrequency::cases()))->required()->description('The unit of the interval.'),
            'times' => $schema->integer()->required()->description('How many more times the post repeats (1-100).'),
        ];
    }
}
