<script setup lang="ts">
import { computed } from 'vue';

import ChangelogTagBadge from '@/components/changelog/ChangelogTagBadge.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import date from '@/date';
import { filled } from '@/lib/helpers';
import type { ChangelogEntry } from '@/types/changelog';

/**
 * A What's New entry as the user saw it, with what they answered. Read only:
 * opening it here never records a view or a reaction for the admin.
 */
const props = defineProps<{
    entry: ChangelogEntry | null;
    userName: string;
}>();

const emit = defineEmits<{
    close: [];
}>();

const isOpen = computed(() => filled(props.entry));

const hasTags = computed(() => filled(props.entry?.tags));

const reactionLabel = computed((): string => {
    if (props.entry?.reaction === 'like') {
        return `👍 ${props.userName} liked it`;
    }

    if (props.entry?.reaction === 'dislike') {
        return `👎 ${props.userName} disliked it`;
    }

    return `${props.userName} opened it without reacting`;
});

const onOpenChange = (open: boolean): void => {
    if (!open) {
        emit('close');
    }
};
</script>

<template>
    <Dialog :open="isOpen" @update:open="onOpenChange">
        <DialogContent
            class="gap-0 px-0 pt-0 pb-0 sm:max-h-[min(640px,80dvh)] sm:max-w-lg"
            data-testid="admin-changelog-entry-dialog"
        >
            <article v-if="entry" class="flex flex-col gap-4 px-6 pt-12 pb-6">
                <div class="aspect-video w-full overflow-hidden rounded-xl bg-muted">
                    <img
                        v-if="entry.cover_url"
                        :src="entry.cover_url"
                        alt=""
                        class="size-full object-cover"
                    />
                </div>
                <div class="flex flex-col gap-1">
                    <div v-if="hasTags" class="mb-1 flex flex-wrap gap-1.5">
                        <ChangelogTagBadge v-for="tag in entry.tags" :key="tag.value" :tag="tag" />
                    </div>
                    <DialogTitle class="text-xl leading-7 font-medium text-foreground">
                        {{ entry.title }}
                    </DialogTitle>
                    <DialogDescription class="text-sm text-muted-foreground">
                        {{ date.formatDateOnly(entry.published_on) }}
                    </DialogDescription>
                </div>
                <div
                    class="prose prose-base max-w-none text-foreground dark:prose-invert prose-a:text-primary-text"
                    v-html="entry.body_html"
                />
                <p
                    class="mt-2 border-t border-border pt-5 text-center text-sm font-medium text-foreground"
                    data-testid="admin-changelog-entry-reaction"
                >
                    {{ reactionLabel }}
                </p>
            </article>
        </DialogContent>
    </Dialog>
</template>
