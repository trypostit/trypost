<script setup lang="ts">
import { InfiniteScroll, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import LabelBadge from '@/components/labels/LabelBadge.vue';
import PostBoardCard from '@/components/publish/board/PostBoardCard.vue';
import { blank, filled } from '@/lib/helpers';
import { formatNumberCompact } from '@/lib/utils';
import type { BoardColumnMeta, BoardGroup } from '@/types/board';
import { channelName } from '@/types/channel';
import type { ScrollPostCards } from '@/types/publish';

const props = defineProps<{
    column: BoardColumnMeta;
    group: BoardGroup;
    timezone: string;
}>();

const page = usePage();

const cards = computed(
    (): ScrollPostCards | undefined =>
        (page.props.columns as Record<string, ScrollPostCards> | undefined)?.[
            props.column.key
        ],
);

const posts = computed(() => cards.value?.data ?? []);

/** Every post of the column, loaded or not: the paginator's total. */
const count = computed(() => cards.value?.total ?? 0);

/** The exact count on hover, once the badge shortens it ("1.5K"). */
const countTitle = computed((): string | undefined => {
    if (count.value < 1000) {
        return undefined;
    }

    return String(count.value);
});

/** The cards show their status unless the columns already are the statuses. */
const showsCardStatus = computed(() => props.group !== 'status');

/** A status or Untagged column is named by a lang key. */
const nameKey = computed((): string => {
    if (props.column.type === 'status') {
        return `posts.board.columns.${props.column.key}`;
    }

    return `posts.board.columns.${props.column.type}`;
});

/**
 * The column's name: the tag's or the channel's own name, else its lang key
 * resolved with the template's `$t`.
 */
const columnName = (translate: (key: string) => string): string => {
    if (filled(props.column.label)) {
        return props.column.label.name;
    }

    if (filled(props.column.channel)) {
        return channelName(props.column.channel);
    }

    return translate(nameKey.value);
};
</script>

<template>
    <section
        class="flex h-full w-[85vw] shrink-0 snap-start flex-col rounded-lg bg-muted sm:w-80"
        :aria-label="
            $tChoice('posts.board.column_aria', count, {
                name: columnName($t),
                count: String(count),
            })
        "
        :data-testid="`board-column-${column.key}`"
    >
        <header class="flex h-12 shrink-0 items-center gap-2 ps-3 pe-3 pt-2">
            <LabelBadge
                v-if="column.label"
                :label="column.label"
                :data-testid="`board-column-label-${column.key}`"
            />
            <template v-else-if="column.channel">
                <ChannelAvatar
                    :platform="column.channel.platform"
                    :src="column.channel.avatar_url"
                    :name="channelName(column.channel)"
                    :status="column.channel.status"
                    :account-id="column.channel.id"
                    :size="20"
                    ring="background"
                />
                <h2
                    class="min-w-0 truncate text-sm leading-5 font-emphasis text-foreground"
                    :title="channelName(column.channel)"
                >
                    {{ channelName(column.channel) }}
                </h2>
            </template>
            <h2
                v-else
                class="min-w-0 truncate text-sm leading-5 font-emphasis text-foreground"
            >
                {{ columnName($t) }}
            </h2>
            <span
                v-if="cards"
                class="inline-flex h-4.5 min-w-4.5 shrink-0 items-center justify-center rounded-full bg-secondary px-1 text-xs leading-[18px] font-medium text-muted-foreground"
                :title="countTitle"
                :data-testid="`board-column-count-${column.key}`"
                >{{ formatNumberCompact(count) }}</span
            >
        </header>

        <div
            v-if="!cards"
            class="flex flex-col gap-2 px-2 pt-1"
            aria-hidden="true"
            :data-testid="`board-column-skeleton-${column.key}`"
        >
            <div
                v-for="index in 3"
                :key="index"
                class="h-32 animate-pulse rounded-lg bg-secondary"
            />
        </div>
        <div
            v-else
            class="min-h-0 flex-1 overflow-y-auto overscroll-y-contain px-2 pt-1 pb-2"
            :data-testid="`board-column-scroll-${column.key}`"
        >
            <InfiniteScroll
                :data="`columns.${column.key}`"
                :buffer="200"
                only-next
                preserve-url
            >
                <div class="flex flex-col gap-2">
                    <PostBoardCard
                        v-for="post in posts"
                        :key="post.id"
                        :post="post"
                        :show-status="showsCardStatus"
                        :timezone="timezone"
                    />
                    <p
                        v-if="blank(posts)"
                        class="px-1 py-2 text-sm text-muted-foreground"
                        :data-testid="`board-column-empty-${column.key}`"
                    >
                        {{ $t('posts.board.empty_column') }}
                    </p>
                </div>
            </InfiniteScroll>
        </div>
    </section>
</template>
