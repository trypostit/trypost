<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconCheck, IconSend } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import { approve } from '@/actions/App/Http/Controllers/App/PostApprovalController';
import ComposerSchedulePicker from '@/components/posts/composer/ComposerSchedulePicker.vue';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    POST_CARD_APPROVAL_RELOAD,
    toastFirstError,
    usePostCardReload,
} from '@/composables/usePostCardActions';
import date from '@/date';
import { filled } from '@/lib/helpers';
import { userTimezone } from '@/preferences';
import { ScheduleMode } from '@/types/post';
import type { PostCard } from '@/types/publish';

const props = defineProps<{
    post: PostCard;
    testKey: string;
}>();

const reload = usePostCardReload(POST_CARD_APPROVAL_RELOAD);

const pickerOpen = ref(false);

const approving = ref(false);

const closePicker = (): void => {
    pickerOpen.value = false;
};

const isQueued = computed(
    () => props.post.schedule_mode === ScheduleMode.Queue,
);

const postTimezone = computed(
    () => props.post.social_account?.timezone ?? userTimezone.value,
);

/** A custom-time post without a time still to come asks for one before it is approved. */
const needsTime = computed(
    () =>
        !isQueued.value &&
        (!filled(props.post.scheduled_at) ||
            date.hasPassed(props.post.scheduled_at)),
);

/** Approving a post as it was asked for: into the queue, or at its time. */
const asRequested = computed(() => {
    if (isQueued.value) {
        return {
            labelKey: 'posts.publish.actions.add_to_queue',
            toastKey: 'posts.approvals.toasts.queued',
        };
    }

    return {
        labelKey: 'posts.approvals.schedule',
        toastKey: 'posts.approvals.toasts.scheduled',
    };
});

/** Sends the approval and toasts what it did, named after the button the approver clicked. */
const send = (
    payload: { scheduled_at?: string; publish_now?: true },
    toastKey: string,
): void => {
    router.put(approve.url(props.post.id), payload, {
        ...reload(),
        preserveScroll: true,
        onStart: () => {
            approving.value = true;
        },
        onFinish: () => {
            approving.value = false;
        },
        onSuccess: () => {
            pickerOpen.value = false;
            toast.success(trans(toastKey), { testId: 'post-approval-toast' });
        },
        onError: toastFirstError,
    });
};

const approveAsRequested = (): void => {
    send({}, asRequested.value.toastKey);
};

const approveAt = (value: string): void => {
    send(
        { scheduled_at: date.wallClockToUtc(value, postTimezone.value) },
        'posts.approvals.toasts.scheduled',
    );
};

const publishNow = (): void => {
    send({ publish_now: true }, 'posts.approvals.toasts.publishing');
};
</script>

<template>
    <Popover v-if="needsTime" v-model:open="pickerOpen">
        <PopoverTrigger as-child>
            <Button
                variant="outline"
                :loading="approving"
                :data-testid="`post-approve-${testKey}`"
            >
                <IconCheck class="-mx-0.5 size-4 text-success-text" stroke-width="2.25" />
                {{ $t('posts.approvals.approve') }}
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
                @confirm="approveAt"
            />
            <div class="border-t border-border-strong p-3">
                <Button
                    variant="outline"
                    class="w-full"
                    :loading="approving"
                    :data-testid="`post-approve-publish-now-${testKey}`"
                    @click="publishNow"
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
        :loading="approving"
        :data-testid="`post-approve-${testKey}`"
        @click="approveAsRequested"
    >
        <IconCheck class="-mx-0.5 size-4 text-success-text" stroke-width="2.25" />
        {{ $t(asRequested.labelKey) }}
    </Button>
</template>
