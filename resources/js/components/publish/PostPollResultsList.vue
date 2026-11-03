<script setup lang="ts">
import { computed } from 'vue';

import PostPollResults from '@/components/publish/PostPollResults.vue';
import { filled } from '@/lib/helpers';
import type {
    PublicationPoll,
    PublicationThreadPoll,
} from '@/types/analytics';

const props = defineProps<{
    poll: PublicationPoll | null;
    threadPolls: PublicationThreadPoll[];
    /** The post poll's test id; each reply poll's adds `-reply-{n}`. */
    testId: string;
}>();

const hasPolls = computed(
    (): boolean => filled(props.poll) || filled(props.threadPolls),
);
</script>

<template>
    <div v-if="hasPolls" class="flex flex-col gap-3 px-4 pb-3">
        <PostPollResults v-if="poll" :poll="poll" :test-id="testId" />
        <PostPollResults
            v-for="threadPoll in threadPolls"
            :key="threadPoll.reply"
            :poll="threadPoll"
            :caption="
                $t('posts.poll_results.thread_post', {
                    number: String(threadPoll.reply + 2),
                })
            "
            :test-id="`${testId}-reply-${threadPoll.reply}`"
        />
    </div>
</template>
