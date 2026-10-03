<script setup lang="ts">
import {
    IconDots,
    IconHeart,
    IconMail,
    IconMessageCircle,
    IconPlus,
    IconRepeat,
    IconRestore,
    IconSend,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import PreviewTextPost from '@/components/posts/previews/PreviewTextPost.vue';
import { ContentType } from '@/types/content-type';

import type { PreviewProps } from './types';

const props = defineProps<PreviewProps>();

const isGhost = computed(
    (): boolean => props.contentType === ContentType.ThreadsGhostPost,
);
</script>

<template>
    <PreviewTextPost
        data-testid="threads-preview"
        :account="socialAccount"
        :content="content"
        :media="isGhost ? [] : media"
        :name="socialAccount.username || socialAccount.handle_label"
        link-tone="info"
        host-only
        tags="bare"
        media-layout="peek"
        mute-badge
        :link-card="!isGhost"
        :content-testid="isGhost ? 'threads-ghost-bubble' : undefined"
        :text-class="
            isGhost
                ? 'mt-1.5 w-fit rounded-2xl border border-dashed border-border-strong px-3 py-2'
                : undefined
        "
        actions-style="start"
        :actions="
            isGhost
                ? []
                : [
                      { icon: IconHeart },
                      { icon: IconMessageCircle },
                      { icon: IconRepeat },
                      { icon: IconSend },
                  ]
        "
    >
        <template #avatar-badge>
            <span
                class="absolute -right-1 -bottom-1 flex size-4 items-center justify-center rounded-full bg-foreground text-background ring-2 ring-card"
            >
                <IconPlus class="size-2.5" stroke-width="3" />
            </span>
        </template>
        <template v-if="isGhost" #aside>
            <IconDots class="ml-auto size-4 shrink-0 self-center" />
        </template>
        <div
            v-if="isGhost"
            class="flex items-center gap-6 pt-1 text-muted-foreground"
        >
            <IconHeart class="size-[18px]" stroke-width="1.75" />
            <IconMail class="size-[18px]" stroke-width="1.75" />
            <span
                class="flex items-center gap-1.5 text-xs text-foreground"
                data-testid="threads-ghost-expiry"
            >
                <IconRestore class="size-4" stroke-width="1.75" />
                {{ $t('posts.composer.preview.ghost_expires') }}
            </span>
        </div>
    </PreviewTextPost>
</template>
