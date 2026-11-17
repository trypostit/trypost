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
import { pollLimitsOf } from '@/lib/poll';
import { ContentType } from '@/types/content-type';
import type { Poll } from '@/types/poll';


const props = defineProps<PreviewProps>();

const contentWarning = computed((): string =>
    String(props.meta?.spoiler_text ?? '').trim(),
);

/** The poll the status shows: only a Poll post has one, whatever its meta still holds. */
const poll = computed((): Partial<Poll> | undefined => {
    if (props.contentType !== ContentType.MastodonPoll) {
        return undefined;
    }

    return props.meta?.poll;
});

const pollLimits = computed(() => pollLimitsOf(props.publishConfig));

/** A poll shows no link card. */
const showsLinkCard = computed((): boolean => blank(poll.value));

/** The poll shows once the account's poll limits are known, and stays behind a content warning like the rest of the status. */
const showsPoll = computed((): boolean => {
    if (!filled(poll.value) || !filled(pollLimits.value)) {
        return false;
    }

    return contentWarning.value === '';
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
        <template v-if="showsPoll" #default>
            <MastodonPreviewPoll :poll="poll!" :limits="pollLimits!" />
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
