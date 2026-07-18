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
        class="flex shrink-0 gap-1 overflow-x-auto border-b-2 border-foreground bg-card py-3 pr-2 lg:hidden [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        :class="isTopBar ? 'pl-16' : 'pl-2'"
    >
        <button
            v-for="item in items"
            :key="item.key"
            type="button"
            :data-testid="`editor-nav-${item.key}`"
            class="inline-flex h-10 shrink-0 items-center rounded-md border-2 px-3 text-sm font-semibold transition-colors"
            :class="activeView === item.key
                ? 'border-foreground bg-violet-100 text-foreground'
                : 'border-transparent text-foreground/60 hover:text-foreground'"
            @click="activeView = item.key"
        >
            {{ $t(item.label) }}
        </button>
    </div>
</template>
