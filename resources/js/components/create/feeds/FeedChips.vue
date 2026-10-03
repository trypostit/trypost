<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconChevronDown, IconFolder } from '@tabler/icons-vue';
import { computed } from 'vue';

import FeedFavicon from '@/components/create/feeds/FeedFavicon.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { index, show } from '@/routes/app/create/feeds';
import { show as showCollection } from '@/routes/app/create/feeds/collections';
import type {
    RssFeed,
    RssFeedCollection,
    RssFeedsScope,
} from '@/types/rssFeed';

const props = defineProps<{
    feeds: RssFeed[];
    collections: RssFeedCollection[];
    scope: RssFeedsScope;
}>();

const activeFeedId = computed(() =>
    props.scope.kind === 'feed' ? props.scope.feed.id : null,
);

const activeCollectionId = computed(() => {
    if (props.scope.kind === 'collection') {
        return props.scope.collection.id;
    }

    if (props.scope.kind === 'feed') {
        const feedId = props.scope.feed.id;

        return (
            props.feeds.find((feed) => feed.id === feedId)
                ?.rss_feed_collection_id ?? null
        );
    }

    return null;
});

const looseFeeds = computed(() =>
    props.feeds.filter((feed) => feed.rss_feed_collection_id === null),
);

const feedsOf = (collectionId: string): RssFeed[] =>
    props.feeds.filter((feed) => feed.rss_feed_collection_id === collectionId);

const chipClass = (active: boolean): string =>
    active
        ? 'border-transparent bg-primary-selected text-primary-text'
        : 'border-border-strong bg-card text-foreground hover:bg-accent';
</script>

<template>
    <div class="flex shrink-0 flex-col gap-2 px-4 pt-4 md:px-8">
        <nav
            class="flex items-center gap-2 overflow-x-auto sm:flex-wrap"
            :aria-label="$t('create.feeds.feeds_root')"
            data-testid="feeds-chips"
        >
            <Link
                :href="index.url()"
                :aria-current="scope.kind === 'all' ? 'page' : undefined"
                data-testid="feeds-chip-all"
                class="inline-flex h-8 shrink-0 items-center gap-2 rounded-full border px-3 text-sm font-medium transition-control"
                :class="chipClass(scope.kind === 'all')"
            >
                {{ $t('create.feeds.all_feeds') }}
            </Link>
            <Link
                v-for="feed in looseFeeds"
                :key="feed.id"
                :href="show.url(feed.id)"
                :aria-current="activeFeedId === feed.id ? 'page' : undefined"
                :data-testid="`feeds-chip-feed-${feed.id}`"
                class="inline-flex h-8 max-w-60 shrink-0 items-center gap-2 rounded-full border px-3 text-sm font-medium transition-control"
                :class="chipClass(activeFeedId === feed.id)"
            >
                <FeedFavicon :url="feed.icon_url" :size="12" />
                <span class="truncate">{{ feed.display_title }}</span>
            </Link>
            <span
                v-for="collection in collections"
                :key="collection.id"
                class="inline-flex h-8 max-w-64 shrink-0 items-center rounded-full border text-sm font-medium transition-control"
                :class="chipClass(activeCollectionId === collection.id)"
                :data-testid="`feeds-collection-chip-${collection.id}`"
            >
                <Link
                    :href="showCollection.url(collection.id)"
                    :aria-current="
                        scope.kind === 'collection' &&
                        scope.collection.id === collection.id
                            ? 'page'
                            : undefined
                    "
                    :data-testid="`feeds-chip-collection-${collection.id}`"
                    class="inline-flex h-full min-w-0 items-center gap-2 rounded-l-full pr-1 pl-3"
                >
                    <IconFolder class="size-4 shrink-0" />
                    <span class="truncate">{{ collection.name }}</span>
                </Link>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="inline-flex h-full shrink-0 items-center rounded-r-full pr-2 pl-1 data-[state=open]:[&>svg]:rotate-180"
                            :aria-label="$t('create.feeds.collection_menu', { name: collection.name })"
                            :data-testid="`feeds-collection-menu-${collection.id}`"
                        >
                            <IconChevronDown
                                class="size-4 transition-transform"
                            />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="start" class="w-56">
                        <DropdownMenuItem
                            v-for="member in feedsOf(collection.id)"
                            :key="member.id"
                            as-child
                        >
                            <Link
                                :href="show.url(member.id)"
                                :aria-current="
                                    activeFeedId === member.id
                                        ? 'page'
                                        : undefined
                                "
                                :data-testid="`feeds-collection-menu-item-${member.id}`"
                            >
                                <FeedFavicon :url="member.icon_url" :size="16" />
                                <span class="flex-1 truncate">{{
                                    member.display_title
                                }}</span>
                            </Link>
                        </DropdownMenuItem>
                        <p
                            v-if="feedsOf(collection.id).length === 0"
                            class="px-2 py-1.5 text-sm text-muted-foreground"
                        >
                            {{ $t('create.feeds.empty_collection.title') }}
                        </p>
                    </DropdownMenuContent>
                </DropdownMenu>
            </span>
        </nav>
    </div>
</template>
