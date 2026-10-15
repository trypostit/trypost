<script setup lang="ts">
import { InfiniteScroll, router, useHttp, usePage } from '@inertiajs/vue3';
import { IconArrowLeft, IconX } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import { update as updateReaction } from '@/actions/App/Http/Controllers/App/ChangelogEntryReactionController';
import { store as storeView } from '@/actions/App/Http/Controllers/App/ChangelogEntryViewController';
import ChangelogTagBadge from '@/components/changelog/ChangelogTagBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useChangelogDialog } from '@/composables/changelog/useChangelogDialog';
import date from '@/date';
import type {
    ChangelogEntry,
    ChangelogReaction,
    ScrollChangelogEntries,
} from '@/types/changelog';

const page = usePage();
const { state, openEntry, backToList, close } = useChangelogDialog();
const http = useHttp<Record<string, never>, null>({});
const reactionHttp = useHttp<{ reaction: ChangelogReaction | null }, null>({
    reaction: null,
});

const openedIds = ref(new Set<string>());
const reactions = ref<Record<string, ChangelogReaction | null>>({});

const entries = computed(
    () =>
        (page.props.changelogEntries as ScrollChangelogEntries | undefined)
            ?.data ?? null,
);
const activeEntry = computed(() => state.value?.entry ?? null);
const isOpen = computed(() => state.value !== null);

const isUnseen = (entry: ChangelogEntry): boolean =>
    !entry.seen && !openedIds.value.has(entry.id);

const loadEntries = (): void => {
    router.reload({
        only: ['changelogEntries'],
        reset: ['changelogEntries'],
    });
};

const recordView = async (entry: ChangelogEntry): Promise<void> => {
    if (!isUnseen(entry)) {
        return;
    }

    openedIds.value = new Set(openedIds.value).add(entry.id);

    try {
        await http.post(storeView.url(entry.id));
        router.reload({ only: ['changelog'] });
    } catch {
        const next = new Set(openedIds.value);
        next.delete(entry.id);
        openedIds.value = next;
    }
};

const reactionOf = (entry: ChangelogEntry): ChangelogReaction | null =>
    entry.id in reactions.value ? reactions.value[entry.id] : entry.reaction;

const react = async (
    entry: ChangelogEntry,
    reaction: ChangelogReaction,
): Promise<void> => {
    const previous = reactionOf(entry);
    const next = previous === reaction ? null : reaction;

    reactions.value = { ...reactions.value, [entry.id]: next };
    reactionHttp.reaction = next;

    try {
        await reactionHttp.put(updateReaction.url(entry.id));
    } catch {
        reactions.value = { ...reactions.value, [entry.id]: previous };
        toast.error("Couldn't save your answer. Try again.");
    }
};

const isDimmed = (
    entry: ChangelogEntry,
    reaction: ChangelogReaction,
): boolean => {
    const current = reactionOf(entry);

    return current !== null && current !== reaction;
};

const like = (entry: ChangelogEntry): void => {
    void react(entry, 'like');
};

const dislike = (entry: ChangelogEntry): void => {
    void react(entry, 'dislike');
};

const showEntry = (entry: ChangelogEntry): void => {
    openEntry(entry);
};

const handleOpenChange = (open: boolean): void => {
    if (!open) {
        close();
    }
};

watch(
    () => state.value?.id,
    (id, previousId) => {
        if (id !== undefined && id !== previousId) {
            loadEntries();
        }
    },
);

watch(activeEntry, (entry) => {
    if (entry) {
        void recordView(entry);
    }
});
</script>

<template>
    <Dialog :open="isOpen" @update:open="handleOpenChange">
        <DialogContent
            class="gap-0 px-0 pt-0 pb-0 sm:max-h-[min(640px,80dvh)] sm:max-w-lg"
            :show-close-button="false"
            data-testid="changelog-dialog"
        >
            <div
                class="sticky top-0 z-10 flex h-16 shrink-0 items-center gap-2 bg-background px-6"
            >
                <Button
                    v-if="activeEntry"
                    variant="ghost"
                    size="icon"
                    class="-ms-2"
                    :aria-label="$t('common.back')"
                    data-testid="changelog-back"
                    @click="backToList"
                >
                    <IconArrowLeft class="size-4" />
                </Button>
                <DialogTitle class="text-lg font-semibold">
                    What's New
                </DialogTitle>
                <DialogDescription class="sr-only">
                    What's New
                </DialogDescription>
                <DialogClose as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="-me-2 ms-auto"
                        :aria-label="$t('common.close')"
                        data-testid="changelog-close"
                    >
                        <IconX class="size-4" />
                    </Button>
                </DialogClose>
            </div>

            <article
                v-if="activeEntry"
                class="flex flex-col gap-4 px-6 pb-6"
                data-testid="changelog-entry"
            >
                <img
                    :src="activeEntry.cover_url"
                    alt=""
                    class="aspect-video w-full rounded-xl object-cover"
                />
                <div class="flex flex-col gap-1">
                    <div
                        v-if="activeEntry.tags.length"
                        class="mb-1 flex flex-wrap gap-1.5"
                        data-testid="changelog-entry-tags"
                    >
                        <ChangelogTagBadge
                            v-for="tag in activeEntry.tags"
                            :key="tag.value"
                            :tag="tag"
                        />
                    </div>
                    <h2
                        class="text-xl leading-7 font-medium text-foreground"
                        data-testid="changelog-entry-title"
                    >
                        {{ activeEntry.title }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ date.formatDateOnly(activeEntry.published_on) }}
                    </p>
                </div>
                <div
                    class="prose prose-base max-w-none text-foreground dark:prose-invert prose-a:text-primary-text"
                    v-html="activeEntry.body_html"
                />
                <div
                    class="mt-2 flex items-center justify-center gap-2 border-t border-border pt-5"
                    data-testid="changelog-reaction"
                >
                    <p class="text-sm font-medium text-foreground">
                        Do you like it?
                    </p>
                    <div class="-me-1 flex items-center gap-1">
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Like"
                            :aria-pressed="reactionOf(activeEntry) === 'like'"
                            class="group/reaction size-auto p-1 hover:bg-transparent"
                            data-testid="changelog-reaction-like"
                            @click="like(activeEntry)"
                        >
                            <span
                                class="block text-xl leading-none transition duration-200 ease-out will-change-transform motion-safe:group-hover/reaction:scale-125"
                                :class="{
                                    'opacity-50 grayscale': isDimmed(
                                        activeEntry,
                                        'like',
                                    ),
                                }"
                                aria-hidden="true"
                                >👍</span
                            >
                        </Button>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Dislike"
                            :aria-pressed="reactionOf(activeEntry) === 'dislike'"
                            class="group/reaction size-auto p-1 hover:bg-transparent"
                            data-testid="changelog-reaction-dislike"
                            @click="dislike(activeEntry)"
                        >
                            <span
                                class="block text-xl leading-none transition duration-200 ease-out will-change-transform motion-safe:group-hover/reaction:scale-125"
                                :class="{
                                    'opacity-50 grayscale': isDimmed(
                                        activeEntry,
                                        'dislike',
                                    ),
                                }"
                                aria-hidden="true"
                                >👎</span
                            >
                        </Button>
                    </div>
                </div>
            </article>

            <div v-else class="px-6 pb-6">
                <div
                    v-if="entries === null"
                    class="flex flex-col gap-5"
                    aria-hidden="true"
                >
                    <div
                        v-for="index in 3"
                        :key="index"
                        class="h-24 animate-pulse rounded-lg bg-secondary"
                    />
                </div>
                <p
                    v-else-if="entries.length === 0"
                    class="py-10 text-center text-sm text-muted-foreground"
                    data-testid="changelog-empty"
                >
                    Nothing new yet. New features will show up here.
                </p>
                <InfiniteScroll
                    v-else
                    data="changelogEntries"
                    items-element="#changelog-list"
                    only-next
                    preserve-url
                >
                    <div id="changelog-list" class="flex flex-col gap-4">
                        <button
                            v-for="entry in entries"
                            :key="entry.id"
                            type="button"
                            class="-mx-3 flex flex-col gap-3 rounded-xl p-3 text-start transition-control hover:bg-accent focus-visible:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                            :data-testid="`changelog-item-${entry.slug}`"
                            @click="showEntry(entry)"
                        >
                            <span
                                class="group/cover relative block overflow-hidden rounded-lg bg-muted"
                                :data-testid="`changelog-item-cover-${entry.slug}`"
                            >
                                <img
                                    :src="entry.cover_url"
                                    alt=""
                                    class="aspect-video w-full object-cover transition-transform duration-500 ease-out motion-safe:group-hover/cover:scale-[1.04]"
                                    draggable="false"
                                />
                            </span>
                            <span class="flex min-w-0 flex-col gap-1">
                                <span
                                    class="flex flex-wrap items-center gap-1.5"
                                >
                                    <ChangelogTagBadge
                                        v-for="tag in entry.tags"
                                        :key="tag.value"
                                        :tag="tag"
                                    />
                                    <span
                                        v-if="entry.tags.length"
                                        class="size-1 rounded-full bg-muted-foreground/50"
                                        aria-hidden="true"
                                    />
                                    <span class="text-xs text-muted-foreground">
                                        {{
                                            date.formatDateOnly(
                                                entry.published_on,
                                            )
                                        }}
                                    </span>
                                </span>
                                <span
                                    class="text-base leading-6 font-medium text-foreground"
                                    :data-testid="`changelog-item-title-${entry.slug}`"
                                >
                                    <span
                                        v-if="isUnseen(entry)"
                                        class="me-1.5 inline-block size-2 rounded-full bg-primary-strong align-[2px]"
                                        :data-testid="`changelog-unseen-${entry.slug}`"
                                    />
                                    {{ entry.title }}
                                </span>
                                <span
                                    class="line-clamp-2 text-sm text-muted-foreground"
                                >
                                    {{ entry.excerpt }}
                                </span>
                            </span>
                        </button>
                    </div>
                </InfiniteScroll>
            </div>
        </DialogContent>
    </Dialog>
</template>
