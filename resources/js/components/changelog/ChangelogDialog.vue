<script setup lang="ts">
import { InfiniteScroll, router, useHttp, usePage } from '@inertiajs/vue3';
import { IconArrowLeft, IconX } from '@tabler/icons-vue';
import { computed, ref, watch } from 'vue';

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
    ScrollChangelogEntries,
} from '@/types/changelog';

const page = usePage();
const { state, openEntry, backToList, close } = useChangelogDialog();
const http = useHttp<Record<string, never>, null>({});

const openedIds = ref(new Set<string>());

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
            class="gap-0 px-0 pt-0 pb-0 sm:max-w-lg"
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
                    :aria-label="$t('changelog.back')"
                    data-testid="changelog-back"
                    @click="backToList"
                >
                    <IconArrowLeft class="size-4" />
                </Button>
                <DialogTitle class="text-base font-medium">
                    {{ $t('changelog.title') }}
                </DialogTitle>
                <DialogDescription class="sr-only">
                    {{ $t('changelog.title') }}
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
                    class="aspect-[8/5] w-full rounded-xl object-cover"
                />
                <div class="flex flex-col gap-1">
                    <h2
                        class="text-xl leading-7 font-medium text-foreground"
                        data-testid="changelog-entry-title"
                    >
                        <ChangelogTagBadge
                            v-if="activeEntry.tag"
                            :tag="activeEntry.tag"
                            class="me-1.5"
                        />
                        {{ activeEntry.title }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{ date.formatDateOnly(activeEntry.published_on) }}
                    </p>
                </div>
                <div
                    class="prose prose-sm max-w-none text-foreground dark:prose-invert prose-a:text-primary-text"
                    v-html="activeEntry.body_html"
                />
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
                    {{ $t('changelog.empty') }}
                </p>
                <InfiniteScroll
                    v-else
                    data="changelogEntries"
                    items-element="#changelog-list"
                    only-next
                    preserve-url
                >
                    <div id="changelog-list" class="flex flex-col gap-2">
                        <button
                            v-for="entry in entries"
                            :key="entry.id"
                            type="button"
                            class="-mx-2 flex items-start gap-4 rounded-lg p-2 text-start transition-control hover:bg-secondary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-hidden"
                            :data-testid="`changelog-item-${entry.slug}`"
                            @click="showEntry(entry)"
                        >
                            <span class="flex min-w-0 flex-1 flex-col gap-1">
                                <span
                                    class="text-sm leading-5 font-medium text-foreground"
                                >
                                    <span
                                        v-if="isUnseen(entry)"
                                        class="me-1.5 inline-block size-2 rounded-full bg-primary-strong align-[1px]"
                                        :data-testid="`changelog-unseen-${entry.slug}`"
                                    />
                                    <ChangelogTagBadge
                                        v-if="entry.tag"
                                        :tag="entry.tag"
                                        class="me-1"
                                    />
                                    {{ entry.title }}
                                </span>
                                <span
                                    class="line-clamp-2 text-sm text-muted-foreground"
                                >
                                    {{ entry.excerpt }}
                                </span>
                                <span class="text-xs text-muted-foreground">
                                    {{ date.formatDateOnly(entry.published_on) }}
                                </span>
                            </span>
                            <img
                                :src="entry.cover_url"
                                alt=""
                                class="aspect-[8/5] w-36 shrink-0 rounded-lg object-cover"
                            />
                        </button>
                    </div>
                </InfiniteScroll>
            </div>
        </DialogContent>
    </Dialog>
</template>
