<script setup lang="ts">
import { computed } from 'vue';

import { PostStatus } from '@/types/post';

type MobileView = 'compose' | 'channels' | 'preview' | 'comments';

const props = defineProps<{
    status: string;
}>();

const activeView = defineModel<MobileView>('activeView', { required: true });

const items: { key: MobileView; label: string }[] = [
    { key: 'compose', label: 'posts.edit.tabs.compose' },
    { key: 'channels', label: 'posts.edit.tabs.channels' },
    { key: 'preview', label: 'posts.edit.tabs.preview' },
    { key: 'comments', label: 'posts.edit.tabs.comments' },
];

// When not scheduled the header is hidden on mobile and this nav is the top bar,
// so it must clear the floating hamburger.
const isTopBar = computed(() => props.status !== PostStatus.Scheduled);
</script>

<template>
    <div
        data-testid="editor-mobile-nav"
        class="flex shrink-0 [scrollbar-width:none] gap-1 overflow-x-auto border-b border-border bg-card py-3 pr-2 lg:hidden [&::-webkit-scrollbar]:hidden"
        :class="isTopBar ? 'pl-16' : 'pl-2'"
    >
        <button
            v-for="item in items"
            :key="item.key"
            type="button"
            :data-testid="`editor-nav-${item.key}`"
            class="inline-flex h-10 shrink-0 items-center rounded-md border px-3 text-sm font-semibold shadow-xs transition-[color,background-color,border-color,box-shadow] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400"
            :class="
                activeView === item.key
                    ? 'border-amber-300 bg-amber-200 text-amber-950 hover:bg-amber-300'
                    : 'border-border bg-card text-muted-foreground hover:border-amber-200 hover:bg-amber-50 hover:text-foreground'
            "
            @click="activeView = item.key"
        >
            {{ $t(item.label) }}
        </button>
    </div>
</template>
