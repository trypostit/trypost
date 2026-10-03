<script setup lang="ts">
import { IconLoader2, IconPlus, IconTrash } from '@tabler/icons-vue';
import { computed, nextTick, ref } from 'vue';

import FeedFavicon from '@/components/create/feeds/FeedFavicon.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type {
    RssFeedDirectoryCategory,
    RssFeedDirectoryCategoryKey,
    RssFeedDirectoryEntry,
} from '@/types/rssFeed';

const props = defineProps<{
    directory: RssFeedDirectoryCategory[] | null;
    pendingUrls: Set<string>;
}>();

const emit = defineEmits<{
    close: [];
    add: [entry: RssFeedDirectoryEntry];
    remove: [entry: RssFeedDirectoryEntry];
}>();

const activeKey = ref<RssFeedDirectoryCategoryKey>('favorites');

const selectCategory = (key: RssFeedDirectoryCategoryKey): void => {
    activeKey.value = key;
};

const entries = computed(
    () =>
        props.directory?.find((category) => category.key === activeKey.value)
            ?.entries ?? [],
);

const moveCategory = async (step: number): Promise<void> => {
    const keys = (props.directory ?? []).map((category) => category.key);
    const next =
        keys[(keys.indexOf(activeKey.value) + step + keys.length) % keys.length];

    activeKey.value = next;
    await nextTick();
    document.getElementById(`feeds-explore-tab-${next}`)?.focus();
};

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="true" @update:open="onOpenChange">
        <DialogContent
            class="flex max-h-[85vh] flex-col sm:max-w-3xl"
            :aria-describedby="undefined"
            data-testid="feeds-explore-dialog"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('create.feeds.explore_dialog.title')
                }}</DialogTitle>
            </DialogHeader>

            <div
                v-if="!directory"
                class="flex min-h-0 flex-1 flex-col gap-4"
                aria-busy="true"
                data-testid="feeds-explore-loading"
            >
                <div class="flex shrink-0 gap-2">
                    <div
                        v-for="pill in 5"
                        :key="pill"
                        class="h-8 w-20 animate-pulse rounded-full bg-muted"
                    />
                </div>
                <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                    <div
                        v-for="card in 6"
                        :key="card"
                        class="h-[74px] animate-pulse rounded-lg bg-muted"
                    />
                </div>
            </div>

            <template v-else>
                <div
                    class="flex shrink-0 gap-2 overflow-x-auto pb-1 sm:flex-wrap"
                    role="tablist"
                >
                    <button
                        v-for="category in directory"
                        :key="category.key"
                        type="button"
                        role="tab"
                        :id="`feeds-explore-tab-${category.key}`"
                        aria-controls="feeds-explore-tabpanel"
                        :aria-selected="activeKey === category.key"
                        :tabindex="activeKey === category.key ? 0 : -1"
                        :data-testid="`feeds-explore-category-${category.key}`"
                        class="inline-flex h-8 shrink-0 items-center rounded-full border px-3 text-sm font-medium transition-control"
                        :class="
                            activeKey === category.key
                                ? 'border-transparent bg-primary-selected text-primary-text'
                                : 'border-border-strong bg-card text-foreground hover:bg-accent'
                        "
                        @click="selectCategory(category.key)"
                        @keydown.right.prevent="moveCategory(1)"
                        @keydown.left.prevent="moveCategory(-1)"
                    >
                        {{
                            $t(
                                `create.feeds.explore_dialog.categories.${category.key}`,
                            )
                        }}
                    </button>
                </div>

                <ul
                    id="feeds-explore-tabpanel"
                    role="tabpanel"
                    :aria-labelledby="`feeds-explore-tab-${activeKey}`"
                    class="-mx-1 grid min-h-0 flex-1 grid-cols-1 content-start gap-2 overflow-y-auto px-1 sm:grid-cols-2"
                    :data-testid="`feeds-explore-grid-${activeKey}`"
                >
                    <li
                        v-for="(entry, index) in entries"
                        :key="`${activeKey}-${entry.url}`"
                        class="flex min-w-0 items-center gap-3 rounded-lg border border-border p-3"
                    >
                        <FeedFavicon :url="entry.icon_url" :size="48" />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span class="truncate text-sm font-medium">{{
                                entry.name
                            }}</span>
                            <span
                                class="truncate text-xs text-muted-foreground"
                                >{{ entry.url }}</span
                            >
                        </span>
                        <Button
                            v-if="entry.subscribed"
                            variant="ghost"
                            size="icon"
                            class="shrink-0 text-destructive-text hover:bg-critical-subtle hover:text-destructive-text"
                            :aria-label="
                                $t('create.feeds.explore_dialog.remove', {
                                    name: entry.name,
                                })
                            "
                            :data-testid="`feeds-explore-remove-${index}`"
                            @click="emit('remove', entry)"
                        >
                            <IconTrash class="size-4" />
                        </Button>
                        <Button
                            v-else
                            variant="outline"
                            size="icon"
                            class="shrink-0"
                            :disabled="pendingUrls.has(entry.url)"
                            :aria-label="
                                $t('create.feeds.explore_dialog.add', {
                                    name: entry.name,
                                })
                            "
                            :data-testid="`feeds-explore-add-${index}`"
                            @click="emit('add', entry)"
                        >
                            <IconLoader2
                                v-if="pendingUrls.has(entry.url)"
                                class="size-4 animate-spin"
                            />
                            <IconPlus v-else class="size-4" />
                        </Button>
                    </li>
                </ul>
            </template>

            <slot />
        </DialogContent>
    </Dialog>
</template>
