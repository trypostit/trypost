<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconCheck, IconSend } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import { approve } from '@/actions/App/Http/Controllers/App/PostApprovalController';
import ComposerSchedulePicker from '@/components/posts/composer/ComposerSchedulePicker.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    toastFirstError,
    usePostCardReload,
} from '@/composables/usePostCardActions';
import date from '@/date';
import dayjs from '@/dayjs';
import { userTimezone } from '@/preferences';
import { ScheduleMode } from '@/types/post';
import type { PostCard } from '@/types/publish';

const props = defineProps<{
    post: PostCard;
    testKey: string;
}>();

const reload = usePostCardReload({
    only: ['posts', 'counts', 'queue', 'hasData'],
    reset: ['posts'],
});

const pickerOpen = ref(false);

const closePicker = (): void => {
    pickerOpen.value = false;
};

const isQueued = computed(
    () => props.post.schedule_mode === ScheduleMode.Queue,
);

const postTimezone = computed(
    () => props.post.social_account?.timezone ?? userTimezone.value,
);

const needsTime = computed(
    () =>
        !isQueued.value &&
        (!props.post.scheduled_at ||
            dayjs(props.post.scheduled_at).isBefore(dayjs())),
);

const labelKey = computed(() => {
    if (needsTime.value) {
        return 'posts.approvals.approve';
    }

    return isQueued.value
        ? 'posts.publish.actions.add_to_queue'
        : 'posts.approvals.schedule';
});

const send = (payload: { scheduled_at?: string; publish_now?: true }): void => {
    router.put(approve.url(props.post.id), payload, {
        ...reload(),
        preserveScroll: true,
        onSuccess: () => {
            pickerOpen.value = false;
        },
        onError: toastFirstError,
    });
};

const confirmTime = (value: string): void => {
    send({ scheduled_at: date.wallClockToUtc(value, postTimezone.value) });
};
</script>

<template>
    <Popover v-if="needsTime" v-model:open="pickerOpen">
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                :data-testid="`post-approve-${testKey}`"
            >
                <IconCheck class="-mx-0.5 size-4 text-success-text" stroke-width="2.25" />
                {{ $t(labelKey) }}
            </Button>
        </PopoverTrigger>
        <PopoverContent
            align="end"
            side="top"
            class="w-[21rem] p-0"
            :data-testid="`post-approve-picker-${testKey}`"
        >
            <ComposerSchedulePicker
                model-value=""
                :timezone="postTimezone"
                @back="closePicker"
                @confirm="confirmTime"
            />
            <div class="border-t border-border-strong p-3">
                <Button
                    variant="outline"
                    class="w-full"
                    :data-testid="`post-approve-publish-now-${testKey}`"
                    @click="send({ publish_now: true })"
                >
                    <IconSend class="size-4" />
                    {{ $t('posts.publish.actions.publish_now') }}
                </Button>
            </div>
        </PopoverContent>
    </Popover>
    <Button
        v-else
        variant="outline"
        :data-testid="`post-approve-${testKey}`"
        @click="send({})"
    >
        <IconCheck class="-mx-0.5 size-4 text-success-text" stroke-width="2.25" />
        {{ $t(labelKey) }}
    </Button>
</template>
