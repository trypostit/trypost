<script setup lang="ts">
import {
    IconBookmark,
    IconChartBar,
    IconHeart,
    IconMessageCircle,
    IconRepeat,
    IconUpload,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import PreviewTextPost from '@/components/posts/previews/PreviewTextPost.vue';
import type { PreviewProps } from '@/components/posts/previews/types';
import {
    editablePollOptions,
    formatPollLength,
    pollLimitsOf,
} from '@/lib/xPoll';
import { ContentType } from '@/types/content-type';

const props = defineProps<PreviewProps>();

const pollLimits = computed(() => pollLimitsOf(props.publishConfig));

const poll = computed(() =>
    props.contentType === ContentType.XPoll && props.meta?.poll
        ? props.meta.poll
        : null,
);

const pollOptions = computed((): string[] =>
    poll.value && pollLimits.value
        ? editablePollOptions(poll.value.options, pollLimits.value.minOptions)
        : [],
);

const pollLength = computed((): string => {
    const minutes =
        poll.value?.duration_minutes ?? pollLimits.value?.defaultDuration;

    return minutes === undefined ? '' : formatPollLength(minutes);
});
</script>

<template>
    <PreviewTextPost
        data-testid="x-preview"
        :account="socialAccount"
        :content="content"
        :media="media"
        :handle="`@${socialAccount.username || socialAccount.handle_label}`"
        content-testid="x-preview-content"
        media-layout="grid"
        :link-card="!poll"
        :actions="[
            { icon: IconMessageCircle },
            { icon: IconRepeat },
            { icon: IconHeart },
            { icon: IconChartBar },
        ]"
        :trailing-actions="[{ icon: IconBookmark }, { icon: IconUpload }]"
    >
        <div v-if="poll" class="space-y-2" data-testid="x-preview-poll">
            <div
                v-for="(option, index) in pollOptions"
                :key="index"
                class="min-h-8 truncate rounded-full border border-info px-3 py-1.5 text-center font-bold text-info dark:text-info-text"
            >
                {{ option }}
            </div>
            <p class="text-muted-foreground">
                {{
                    $t('posts.composer.preview.poll_summary', {
                        time: pollLength,
                    })
                }}
            </p>
        </div>
    </PreviewTextPost>
</template>
