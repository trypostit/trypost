<script setup lang="ts">
import {
    IconBulb,
    IconCheck,
    IconExternalLink,
    IconFileText,
    IconLoader2,
    IconPhoto,
} from '@tabler/icons-vue';
import { ref, watch } from 'vue';

import FeedFavicon from '@/components/create/feeds/FeedFavicon.vue';
import { Button } from '@/components/ui/button';
import date from '@/date';
import type { RssFeedItem } from '@/types/rssFeed';

const props = defineProps<{
    item: RssFeedItem;
    saved: boolean;
    saving: boolean;
    creatingPost: boolean;
    busy: boolean;
}>();

const emit = defineEmits<{
    createPost: [];
    saveIdea: [];
}>();

const imageFailed = ref(false);

const markImageFailed = (): void => {
    imageFailed.value = true;
};

watch(
    () => props.item.image_url,
    () => {
        imageFailed.value = false;
    },
);
</script>

<template>
    <article
        class="group relative flex gap-4 rounded-xl border border-transparent p-3 transition-control hover:border-border-strong focus-within:border-border-strong max-sm:flex-col"
        :data-testid="`feed-item-${item.id}`"
    >
        <div
            class="h-[135px] w-[200px] shrink-0 overflow-hidden rounded-lg bg-muted max-sm:h-auto max-sm:w-full max-sm:aspect-[200/135]"
        >
            <img
                v-if="item.image_url && !imageFailed"
                :src="item.image_url"
                alt=""
                loading="lazy"
                referrerpolicy="no-referrer"
                class="size-full object-cover"
                @error="markImageFailed"
            />
            <div
                v-else
                class="flex size-full items-center justify-center text-muted-foreground"
            >
                <IconPhoto class="size-8" stroke-width="1.5" />
            </div>
        </div>

        <div class="flex min-w-0 flex-1 flex-col gap-1.5">
            <component
                :is="item.url ? 'a' : 'span'"
                :href="item.url ?? undefined"
                :target="item.url ? '_blank' : undefined"
                :rel="item.url ? 'noopener noreferrer' : undefined"
                class="inline-flex items-start gap-1.5 text-base leading-6 font-semibold text-foreground"
                :class="{ 'hover:underline': item.url }"
            >
                <span class="line-clamp-2">{{ item.title }}</span>
                <IconExternalLink
                    v-if="item.url"
                    class="mt-1 size-4 shrink-0 text-muted-foreground"
                />
            </component>
            <p
                class="flex min-w-0 items-center gap-1.5 text-sm text-muted-foreground"
            >
                <FeedFavicon :url="item.feed.icon_url" />
                <span class="truncate">{{ item.feed.display_title }}</span>
            </p>
            <p
                v-if="item.excerpt"
                class="line-clamp-3 text-sm leading-5 text-foreground"
            >
                {{ item.excerpt }}
            </p>
            <div class="mt-auto flex flex-wrap items-center justify-between gap-2 pt-1">
                <time
                    :datetime="item.published_at"
                    class="text-xs text-muted-foreground"
                >
                    {{ date.diffForHumans(item.published_at) }}
                </time>
                <div
                    class="flex items-center gap-1 transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 [@media(hover:hover)]:opacity-0"
                >
                    <Button
                        v-if="item.url"
                        variant="outline"
                        size="icon"
                        as-child
                    >
                        <a
                            :href="item.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            :aria-label="
                                $t('create.feeds.open_article', {
                                    title: item.title,
                                })
                            "
                            :data-testid="`feed-item-open-${item.id}`"
                        >
                            <IconExternalLink class="size-4" />
                        </a>
                    </Button>
                    <Button
                        variant="outline"
                        :disabled="busy"
                        :aria-busy="creatingPost"
                        :data-testid="`feed-item-create-post-${item.id}`"
                        @click="emit('createPost')"
                    >
                        <IconLoader2
                            v-if="creatingPost"
                            class="size-4 animate-spin"
                        />
                        <IconFileText v-else class="size-4" />
                        {{ $t('create.feeds.create_post') }}
                    </Button>
                    <Button
                        v-if="saved"
                        variant="outline"
                        disabled
                        :data-testid="`feed-item-saved-${item.id}`"
                    >
                        <IconCheck class="size-4" />
                        {{ $t('create.feeds.saved') }}
                    </Button>
                    <Button
                        v-else
                        variant="outline"
                        :disabled="saving || busy"
                        :aria-busy="saving"
                        :data-testid="`feed-item-save-idea-${item.id}`"
                        @click="emit('saveIdea')"
                    >
                        <IconLoader2 v-if="saving" class="size-4 animate-spin" />
                        <IconBulb v-else class="size-4" />
                        {{ $t('create.feeds.save_idea') }}
                    </Button>
                </div>
            </div>
        </div>
    </article>
</template>
