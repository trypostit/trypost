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

import MastodonPreviewPoll from '@/components/posts/previews/MastodonPreviewPoll.vue';
import PreviewTextPost from '@/components/posts/previews/PreviewTextPost.vue';
import type { PreviewProps } from '@/components/posts/previews/types';
import date from '@/date';
import { blank, filled } from '@/lib/helpers';
import { type PreviewPoll, previewPollOf } from '@/lib/poll';


const props = defineProps<PreviewProps>();

const contentWarning = computed((): string =>
    String(props.meta?.spoiler_text ?? '').trim(),
);

const previewPoll = computed((): PreviewPoll | null =>
    previewPollOf(props.contentType, props.meta, props.publishConfig),
);

/** A poll shows no link card. */
const showsLinkCard = computed((): boolean => blank(previewPoll.value));

/** The poll stays behind a content warning, like the rest of the status. */
const shownPoll = computed((): PreviewPoll | null => {
    if (filled(contentWarning.value)) {
        return null;
    }

    return previewPoll.value;
});
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
        :link-card="showsLinkCard"
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
        <template v-if="shownPoll" #default>
            <MastodonPreviewPoll :poll="shownPoll.poll" :limits="shownPoll.limits" />
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
