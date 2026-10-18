<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconLayoutSidebar, IconList } from '@tabler/icons-vue';
import { computed } from 'vue';

import InboxTooltip from '@/components/inbox/InboxTooltip.vue';
import { inboxViewUrl } from '@/lib/inbox';
import type { InboxFilters, InboxView } from '@/types/inbox';

const props = defineProps<{
    view: InboxView;
    filters: InboxFilters;
}>();

const views = computed(() => [
    {
        key: 'post' as const,
        label: 'inbox.views.post',
        icon: IconLayoutSidebar,
        href: inboxViewUrl('post', props.filters),
    },
    {
        key: 'list' as const,
        label: 'inbox.views.list',
        icon: IconList,
        href: inboxViewUrl('list', props.filters),
    },
]);
</script>

<template>
    <nav
        class="inline-flex h-8 items-center gap-1 rounded-lg border border-border-strong bg-card p-[3px]"
        :aria-label="$t('inbox.views.label')"
    >
        <InboxTooltip
            v-for="option in views"
            :key="option.key"
            :label="$t(option.label)"
        >
        <Link
            :href="option.href"
            :aria-label="$t(option.label)"
            :aria-current="view === option.key ? 'page' : undefined"
            :data-testid="`inbox-view-${option.key}`"
            class="inline-flex h-6 items-center justify-center gap-1 rounded-md border border-transparent px-2 text-sm font-medium transition-control"
            :class="
                view === option.key
                    ? 'bg-primary-selected text-primary-text'
                    : 'text-foreground hover:bg-accent'
            "
        >
            <component :is="option.icon" class="size-4" />
            <span class="hidden sm:inline" aria-hidden="true">{{ $t(option.label) }}</span>
        </Link>
        </InboxTooltip>
    </nav>
</template>
