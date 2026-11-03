<script setup lang="ts">
import { computed } from 'vue';

import PostPollResults from '@/components/publish/PostPollResults.vue';
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
    (): boolean => props.poll !== null || props.threadPolls.length > 0,
);
</script>

<template>
    <div v-if="hasPolls" class="flex flex-col">
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
