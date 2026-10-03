<script setup lang="ts">
import { computed } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import { Badge } from '@/components/ui/badge';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { getPostStatusConfig } from '@/composables/usePostStatus';
import date from '@/date';
import { PostPlatformStatus } from '@/types/post';
import type { PostCard } from '@/types/publish';

const props = defineProps<{
    post: PostCard;
    testKey: string;
    attemptedAt: string | null;
    timezone: string;
}>();

const CATEGORIES = [
    'media_format',
    'rate_limit',
    'permission',
    'content_policy',
    'server_error',
    'platform_unavailable',
    'timeout',
    'token_expired',
    'job_failed',
];

const status = computed(() => getPostStatusConfig(props.post.status));

const failures = computed(() =>
    props.post.post_platforms
        .filter(
            (target) =>
                target.enabled &&
                (target.status === PostPlatformStatus.Failed ||
                    target.status === PostPlatformStatus.Rejected),
        )
        .map((target) => {
            const category = target.error_context?.category ?? '';
            const at = target.error_context?.failed_at ?? props.attemptedAt;

            return {
                id: target.id,
                platform: target.platform,
                account: target.social_account?.display_label ?? null,
                reasonKey: CATEGORIES.includes(category)
                    ? `posts.publish.failure.categories.${category}`
                    : 'posts.publish.failure.generic',
                details: target.error_message?.trim() || null,
                at: at ? date.formatDateTimeInTimezone(at, props.timezone) : null,
            };
        }),
);
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="rounded-full focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                :aria-label="$t('posts.publish.failure.title')"
                :data-testid="`post-status-${testKey}`"
            >
                <Badge
                    :variant="status.variant"
                    class="h-6 cursor-pointer gap-1 px-2 [&>svg]:size-4"
                >
                    <component :is="status.icon" />
                    {{ $t(`posts.status.${post.status}`) }}
                </Badge>
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            class="flex w-80 flex-col gap-4"
            :data-testid="`post-failure-${testKey}`"
        >
            <p class="text-sm font-emphasis text-foreground">
                {{ $t('posts.publish.failure.title') }}
            </p>
            <p
                v-if="failures.length === 0"
                class="text-sm text-foreground"
                :data-testid="`post-failure-reason-${testKey}`"
            >
                {{ $t('posts.publish.failure.generic') }}
            </p>
            <div
                v-for="failure in failures"
                :key="failure.id"
                class="flex flex-col gap-2"
            >
                <p
                    class="flex min-w-0 items-center gap-1.5 text-xs text-muted-foreground"
                >
                    <PlatformBrandIcon :platform="failure.platform" />
                    <span class="truncate">
                        {{ failure.account ?? getPlatformLabel(failure.platform) }}
                    </span>
                    <template v-if="failure.at">
                        <span aria-hidden="true">·</span>
                        <time class="shrink-0">{{ failure.at }}</time>
                    </template>
                </p>
                <p
                    class="text-sm text-foreground"
                    :data-testid="`post-failure-reason-${testKey}`"
                >
                    {{ $t(failure.reasonKey) }}
                </p>
                <div v-if="failure.details" class="flex flex-col gap-1">
                    <p class="text-xs text-muted-foreground">
                        {{ $t('posts.publish.failure.details') }}
                    </p>
                    <p
                        class="rounded-md bg-secondary px-3 py-2 text-xs break-words text-foreground"
                        :data-testid="`post-failure-details-${testKey}`"
                    >
                        {{ failure.details }}
                    </p>
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>
