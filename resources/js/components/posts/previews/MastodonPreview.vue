<script setup lang="ts">
import {
    IconArrowBackUp,
    IconBookmark,
    IconDots,
    IconRepeat,
    IconStar,
    IconWorld,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import PreviewTextPost from '@/components/posts/previews/PreviewTextPost.vue';
import date from '@/date';

import type { PreviewProps } from './types';

const props = defineProps<PreviewProps>();

const contentWarning = computed((): string =>
    String(props.meta?.spoiler_text ?? '').trim(),
);
</script>

<template>
    <PreviewTextPost
        data-testid="mastodon-preview"
        variant="stacked"
        :account="socialAccount"
        :content="content"
        :media="media"
        :name="socialAccount.display_label"
        :handle="`@${socialAccount.username || socialAccount.handle_label}`"
        avatar-class="size-[46px]"
        avatar-square
        link-tone="info"
        host-only
        tags="muted"
        link-card
        :link-card-options="{ band: true, titleFirst: true }"
        :actions="[
            { icon: IconArrowBackUp },
            { icon: IconRepeat },
            { icon: IconStar },
            { icon: IconBookmark },
            { icon: IconDots },
        ]"
        action-class="text-foreground"
        :concealed="contentWarning !== ''"
    >
        <template v-if="contentWarning" #before-media>
            <div
                class="space-y-1 py-2 text-center"
                data-testid="mastodon-preview-content-warning"
            >
                <p class="text-lg font-semibold break-words">
                    {{ contentWarning }}
                </p>
                <p class="text-muted-foreground">
                    {{ $t('posts.composer.preview.tap_to_reveal') }}
                </p>
            </div>
        </template>
        <template #aside>
            <span
                class="flex shrink-0 items-center gap-1 self-start pt-1 text-muted-foreground"
            >
                <IconWorld class="size-3.5" />
                {{ date.formatPreviewPostedAt(postedAt, $t('common.just_now')) }}
            </span>
        </template>
    </PreviewTextPost>
</template>
