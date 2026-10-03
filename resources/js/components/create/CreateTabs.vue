<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import { index as feedsIndex } from '@/routes/app/create/feeds';
import { index as ideasIndex } from '@/routes/app/create/ideas';
import { index as templatesIndex } from '@/routes/app/create/templates';
import type { CreateTab } from '@/types/idea';

defineProps<{
    active: CreateTab;
}>();

const tabs: { key: CreateTab; href: () => string }[] = [
    { key: 'ideas', href: () => ideasIndex.url() },
    { key: 'templates', href: () => templatesIndex.url() },
    { key: 'feeds', href: () => feedsIndex.url() },
];
</script>

<template>
    <div
        class="flex shrink-0 flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 shadow-[inset_0_-1px_0_var(--color-border-strong)]"
    >
        <nav
            class="flex gap-4 overflow-x-auto"
            :aria-label="$t('create.title')"
            data-testid="create-tabs"
        >
            <Link
                v-for="tab in tabs"
                :key="tab.key"
                :href="tab.href()"
                :aria-current="active === tab.key ? 'page' : undefined"
                :data-testid="`create-tab-${tab.key}`"
                class="relative inline-flex h-[45px] shrink-0 items-center px-2 text-sm font-medium transition-control after:absolute after:inset-x-0.5 after:bottom-0 after:h-px after:bg-primary-text after:opacity-0 aria-[current=page]:after:opacity-100"
                :class="
                    active === tab.key
                        ? 'text-foreground'
                        : 'text-muted-foreground hover:text-foreground'
                "
            >
                {{ $t(`create.tabs.${tab.key}`) }}
            </Link>
        </nav>
        <div class="flex items-center gap-2 py-1">
            <slot name="filters" />
        </div>
    </div>
</template>
