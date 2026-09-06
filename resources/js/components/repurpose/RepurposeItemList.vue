<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconCheck,
    IconChevronRight,
    IconClock,
    IconExternalLink,
    IconMinus,
    IconPencil,
} from '@tabler/icons-vue';
import type { Component } from 'vue';

import { getPlatformLabel, getPlatformLogo } from '@/composables/usePlatformLogo';
import date from '@/date';
import { edit } from '@/routes/app/posts';
import type { RepurposeItem, RepurposeItemPost } from '@/types/repurpose';
import { RepurposeItemStatus, type RepurposeItemStatusValue } from '@/types/repurpose-status';

defineProps<{
    items: RepurposeItem[];
}>();

const marks: Record<RepurposeItemStatusValue, { icon: Component; class: string }> = {
    [RepurposeItemStatus.Published]: { icon: IconCheck, class: 'bg-emerald-100 text-emerald-700' },
    [RepurposeItemStatus.Drafted]: { icon: IconPencil, class: 'bg-violet-100 text-violet-700' },
    [RepurposeItemStatus.Pending]: { icon: IconClock, class: 'bg-foreground/5 text-foreground/60' },
    [RepurposeItemStatus.Processing]: { icon: IconClock, class: 'bg-foreground/5 text-foreground/60' },
    [RepurposeItemStatus.Skipped]: { icon: IconMinus, class: 'bg-foreground/5 text-foreground/60' },
    [RepurposeItemStatus.Failed]: { icon: IconAlertTriangle, class: 'bg-rose-100 text-rose-700' },
};

const detail = (item: RepurposeItem): string | null => item.error ?? null;

/**
 * The post's own state, not the item's. A failure on any network is the thing
 * worth surfacing; otherwise a single shared state only reads as settled when
 * every network agrees on it.
 */
const postState = (post: RepurposeItemPost): string | null => {
    const states = post.platforms.map((entry) => entry.status).filter((status): status is string => status !== null);

    if (states.length === 0) {
        return null;
    }

    if (states.includes('failed')) {
        return 'failed';
    }

    return states.every((status) => status === states[0]) ? states[0] : null;
};
</script>

<template>
    <InfiniteScroll data="items" items-element="#repurpose-items-body" preserve-url>
        <ul id="repurpose-items-body" class="divide-y-2 divide-dashed divide-foreground/15">
            <li
                v-for="item in items"
                :key="item.id"
                class="flex flex-wrap items-start gap-3 py-4 first:pt-0 last:pb-0"
                :data-testid="`repurpose-item-${item.id}`"
            >
                <span
                    class="inline-flex size-8 shrink-0 items-center justify-center rounded-full"
                    :class="marks[item.status].class"
                >
                    <component :is="marks[item.status].icon" class="size-4" stroke-width="2.5" />
                </span>

                <div class="min-w-0 flex-1 space-y-1">
                    <p class="text-sm font-bold text-foreground">
                        {{
                            item.reason
                                ? $t(`repurposes.items.reasons.${item.reason}`)
                                : $t(`repurposes.items.statuses.${item.status}`)
                        }}
                    </p>

                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted-foreground">
                        <span :title="date.formatDateTime(item.created_at)">
                            {{ date.diffForHumans(item.created_at) }}
                        </span>

                        <template v-if="item.source_created_at">
                            <span aria-hidden="true">·</span>

                            <a
                                v-if="item.source_permalink"
                                :href="item.source_permalink"
                                target="_blank"
                                rel="noopener noreferrer"
                                :title="date.formatDateTime(item.source_created_at)"
                                class="inline-flex items-center gap-1 underline"
                            >
                                {{ $t('repurposes.items.original_from', { date: date.formatDate(item.source_created_at) }) }}
                                <IconExternalLink class="size-3" />
                            </a>

                            <span v-else :title="date.formatDateTime(item.source_created_at)">
                                {{ $t('repurposes.items.original_from', { date: date.formatDate(item.source_created_at) }) }}
                            </span>
                        </template>
                    </p>

                    <p v-if="detail(item)" class="max-w-prose text-xs break-words text-rose-700">
                        {{ detail(item) }}
                    </p>
                </div>

                <div v-if="(item.posts ?? []).length > 0" class="flex flex-wrap items-center gap-1.5">
                    <a
                        v-for="post in item.posts"
                        :key="post.id"
                        :href="edit.url(post.id)"
                        class="group/post inline-flex items-center gap-1.5 rounded-lg bg-foreground/5 py-1 pr-1.5 pl-2 text-xs font-medium text-foreground transition-colors hover:bg-foreground/10"
                    >
                        <img
                            v-for="entry in post.platforms"
                            :key="entry.platform"
                            :src="getPlatformLogo(entry.platform)"
                            :alt="getPlatformLabel(entry.platform)"
                            class="size-4 rounded-sm"
                            :class="{ 'opacity-40': entry.status === 'failed' }"
                        />

                        {{ post.platforms.map((entry) => getPlatformLabel(entry.platform)).join(', ') }}

                        <span
                            v-if="postState(post)"
                            :class="[
                                'rounded px-1 py-px text-[10px] font-semibold uppercase tracking-wide',
                                postState(post) === 'failed'
                                    ? 'bg-red-500/10 text-red-600 dark:text-red-400'
                                    : 'bg-foreground/10 text-foreground/60',
                            ]"
                        >
                            {{ $t(`posts.status.${postState(post)}`) }}
                        </span>

                        <IconChevronRight class="size-3.5 text-foreground/40 transition-colors group-hover/post:text-foreground" />
                    </a>
                </div>
            </li>
        </ul>
    </InfiniteScroll>
</template>
