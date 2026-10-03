<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import type { PublishCounts, PublishTab } from '@/types/publish';

defineProps<{
    tab: PublishTab;
    counts: PublishCounts;
    hrefFor: (tab: PublishTab) => string;
}>();

const tabs: PublishTab[] = ['queue', 'approvals', 'drafts', 'sent'];
</script>

<template>
    <nav
        class="flex shrink-0 gap-4 overflow-x-auto md:self-end shadow-[inset_0_-1px_0_var(--color-border-strong)] md:overflow-visible md:shadow-none"
        :aria-label="$t('posts.publish.title')"
        data-testid="posts-tabs"
    >
        <Link
            v-for="key in tabs"
            :key="key"
            :href="hrefFor(key)"
            :aria-current="tab === key ? 'page' : undefined"
            :data-testid="`publish-tab-${key}`"
            class="relative inline-flex h-[45px] shrink-0 items-center gap-2 px-2 text-sm font-medium transition-control after:absolute after:inset-x-0.5 after:bottom-0 after:h-px md:after:-bottom-px after:bg-primary-text after:opacity-0 aria-[current=page]:after:opacity-100"
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
</template>
