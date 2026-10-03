<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Post;

use App\Enums\Post\RecurrenceFrequency;
use App\Enums\Post\Status as PostStatus;
use App\Models\Post;
use App\Support\PostingSchedule;
use App\Support\Timezone;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePostRecurrenceRequest extends FormRequest
{
    public const MAX_INTERVAL = 365;

    public const MAX_TIMES = 100;

    public function authorize(): Response
    {
        /** @var Post $post */
        $post = $this->route('post');
        $update = Gate::inspect('update', $post);

        if ($update->denied()) {
            return $update;
        }

        return Gate::inspect('publishDirectly', $post->workspace);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'interval' => ['required', 'integer', 'min:1', 'max:'.self::MAX_INTERVAL],
            'frequency' => ['required', Rule::enum(RecurrenceFrequency::class)],
            'times' => ['required', 'integer', 'min:1', 'max:'.self::MAX_TIMES],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var Post $post */
                $post = $this->route('post');

                if ($post->status !== PostStatus::Scheduled || $post->scheduled_at === null) {
                    $validator->errors()->add('post', __('posts.recurrence.errors.not_scheduled'));

                    return;
                }

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $author = $post->user ?? $post->workspace->owner;
                $last = RecurrenceFrequency::from($this->string('frequency')->toString())->advance(
                    $post->scheduled_at->toImmutable()->setTimezone(Timezone::normalize($author?->timezone)),
                    $this->integer('interval') * $this->integer('times'),
                );

                if ($last->greaterThan(CarbonImmutable::parse(PostingSchedule::MAX_INSTANT, 'UTC'))) {
                    $validator->errors()->add('times', __('posts.recurrence.errors.too_far'));
                }
            },
        ];
    }
}
