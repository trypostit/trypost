<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconCheck, IconChevronDown } from '@tabler/icons-vue';
import { ref } from 'vue';

import ResponsivePopover from '@/components/ResponsivePopover.vue';
import type { PublishCounts, PublishTab } from '@/types/publish';

defineProps<{
    tab: PublishTab;
    counts: PublishCounts;
    hrefFor: (tab: PublishTab) => string;
}>();

const tabs: PublishTab[] = ['queue', 'approvals', 'drafts', 'sent'];

const selectOpen = ref(false);

const closeSelect = (): void => {
    selectOpen.value = false;
};
</script>

<template>
    <div class="flex shrink-0 md:self-end">
        <ResponsivePopover
            v-model:open="selectOpen"
            :title="$t('posts.publish.title')"
            test-id="publish-tabs-sheet"
            align="start"
            content-class="w-56 p-1"
            sheet-class="px-4 pb-6"
        >
            <template #trigger>
                <button
                    type="button"
                    class="-ms-2 inline-flex h-[45px] items-center gap-2 rounded-lg px-2 text-base font-medium text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring md:hidden"
                    :aria-label="$t('posts.publish.title')"
                    data-testid="publish-tabs-mobile-trigger"
                >
                    {{ $t(`posts.publish.tabs.${tab}`) }}
                    <span
                        class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-secondary px-1.5 text-xs font-medium text-foreground"
                        >{{ counts[tab] }}</span
                    >
                    <IconChevronDown class="size-4 text-muted-foreground" />
                </button>
            </template>
            <nav class="flex flex-col gap-1" :aria-label="$t('posts.publish.tabs.label')">
                <p class="px-2 pt-1 pb-0.5 text-xs text-muted-foreground">
                    {{ $t('posts.publish.tabs.label') }}
                </p>
                <Link
                    v-for="key in tabs"
                    :key="key"
                    :href="hrefFor(key)"
                    :aria-current="tab === key ? 'page' : undefined"
                    :data-testid="`publish-tab-sheet-${key}`"
                    class="flex h-9 items-center gap-2 rounded-md px-2 text-sm font-medium text-foreground transition-control hover:bg-accent focus-visible:bg-accent focus-visible:outline-none max-sm:h-11 max-sm:text-base"
                    @click="closeSelect"
                >
                    {{ $t(`posts.publish.tabs.${key}`) }}
                    <span
                        class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-secondary px-1.5 text-xs font-medium text-foreground"
                        >{{ counts[key] }}</span
                    >
                    <IconCheck
                        v-if="tab === key"
                        class="ms-auto size-4 text-foreground"
                        :data-testid="`publish-tab-sheet-${key}-check`"
                    />
                </Link>
            </nav>
        </ResponsivePopover>

        <nav
            class="hidden shrink-0 gap-4 md:flex"
            :aria-label="$t('posts.publish.title')"
            data-testid="posts-tabs"
        >
            <Link
                v-for="key in tabs"
                :key="key"
                :href="hrefFor(key)"
                :aria-current="tab === key ? 'page' : undefined"
                :data-testid="`publish-tab-${key}`"
                class="relative inline-flex h-[45px] shrink-0 items-center gap-2 px-2 text-sm font-medium transition-control after:absolute after:inset-x-0.5 after:-bottom-px after:h-px after:bg-primary-text after:opacity-0 aria-[current=page]:after:opacity-100"
                :class="
                    tab === key
                        ? 'text-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                "
            >
                {{ $t(`posts.publish.tabs.${key}`) }}
                <span
                    class="inline-flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-secondary px-1 text-xs font-medium text-foreground"
                    :data-testid="`publish-tab-count-${key}`"
                    >{{ counts[key] }}</span
                >
            </Link>
        </nav>
    </div>
</template>
