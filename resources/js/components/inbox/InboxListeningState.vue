<script setup lang="ts">
import { IconAccessPoint, IconInfoCircle, IconPencil, IconPlus } from '@tabler/icons-vue';
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { useConnectChannelDialog } from '@/composables/useConnectChannelDialog';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import type { InboxChannel } from '@/types/inbox';

const props = withDefaults(
    defineProps<{
        channels: InboxChannel[];
        backfillDays: number;
        mentions?: boolean;
        messages?: boolean;
    }>(),
    { mentions: false, messages: false },
);

/**
 * Comments on channels that only have reviews (Google Business Profile):
 * nothing there is posted to get people talking, so the state speaks of reviews.
 */
const isReviewsOnly = computed(
    () =>
        !props.mentions &&
        !props.messages &&
        props.channels.length > 0 &&
        props.channels.every((channel) => channel.kinds.includes('review') && !channel.kinds.includes('comment')),
);

/** The copy of the tab the state stands for: comments, reviews, mentions or messages. */
const copyKeys = computed(() => {
    if (props.messages) {
        return {
            title: 'inbox.direct.listening_title',
            description: 'inbox.direct.listening_description',
            how: 'inbox.direct.how_description',
        };
    }

    if (isReviewsOnly.value) {
        return {
            title: 'inbox.listening.reviews_title',
            description: 'inbox.listening.reviews_description',
            how: 'inbox.listening.reviews_how_description',
        };
    }

    return props.mentions
        ? {
              title: 'inbox.mentions.listening_title',
              description: 'inbox.mentions.listening_description',
              how: 'inbox.mentions.how_description',
          }
        : {
              title: 'inbox.listening.title',
              description: 'inbox.listening.description',
              how: 'inbox.listening.how_description',
          };
});

const { canManageAccounts } = useWorkspaceAbilities();
const { open: openConnectDialog } = useConnectChannelDialog();

const listenedChannels = computed(() =>
    props.channels.filter(
        (channel) =>
            channel.has_inbox_access &&
            channel.is_connected &&
            (!props.mentions || (channel.supports_mentions && channel.has_mention_access)) &&
            (!props.messages || (channel.supports_direct_messages && channel.has_direct_message_access)),
    ),
);

const postSomethingNew = (): void => {
    openPostComposer({
        socialAccountIds: listenedChannels.value.map((channel) => channel.id),
    });
};

const connectMoreChannels = (): void => {
    openConnectDialog();
};
</script>

<template>
    <div
        class="mx-auto flex w-full max-w-[500px] flex-col items-center px-4 py-12 text-center"
        data-testid="inbox-listening"
    >
        <div
            class="flex size-12 items-center justify-center rounded-full bg-primary-subtle text-primary-text"
        >
            <IconAccessPoint class="size-6" stroke-width="1.75" aria-hidden="true" />
        </div>
        <h3 class="mt-4 font-heading text-base leading-5 font-medium text-foreground">
            {{ $t(copyKeys.title) }}
        </h3>
        <p class="mt-2 text-sm leading-[21px] text-muted-foreground">
            {{ $t(copyKeys.description) }}
        </p>

        <div
            v-if="listenedChannels.length"
            class="mt-4 flex flex-wrap items-center justify-center gap-2 text-xs leading-[18px] text-muted-foreground"
            data-testid="inbox-listening-channels"
        >
            <span>{{ $t('inbox.listening.channels') }}</span>
            <span
                v-for="channel in listenedChannels"
                :key="channel.id"
                class="inline-flex items-center gap-1.5 rounded-full border border-border bg-card py-0.5 ps-0.5 pe-2 text-foreground"
            >
                <ChannelAvatar
                    :platform="channel.platform"
                    :name="channel.display_name || channel.username"
                    :src="channel.avatar_url"
                    :size="20"
                    ring="card"
                />
                {{ channel.username }}
            </span>
        </div>

        <p class="mt-10 text-xs leading-[18px] text-muted-foreground">
            {{ $t('inbox.listening.meantime') }}
        </p>
        <div class="mt-3 flex w-full flex-col gap-3 text-start">
            <button
                v-if="!isReviewsOnly"
                type="button"
                class="flex w-full gap-2 rounded-lg border border-border bg-card p-4 text-start transition-control hover:border-border-strong focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                data-testid="inbox-listening-post"
                @click="postSomethingNew"
            >
                <IconPencil class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span class="flex min-w-0 flex-col gap-1">
                    <span class="text-sm leading-[21px] font-medium text-foreground">{{
                        $t('inbox.listening.post_title')
                    }}</span>
                    <span class="text-xs leading-[18px] text-muted-foreground">{{
                        $t('inbox.listening.post_description')
                    }}</span>
                </span>
            </button>
            <button
                v-if="canManageAccounts"
                type="button"
                class="flex w-full gap-2 rounded-lg border border-border bg-card p-4 text-start transition-control hover:border-border-strong focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                data-testid="inbox-listening-connect"
                @click="connectMoreChannels"
            >
                <IconPlus class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span class="flex min-w-0 flex-col gap-1">
                    <span class="text-sm leading-[21px] font-medium text-foreground">{{
                        $t('inbox.listening.connect_title')
                    }}</span>
                    <span class="text-xs leading-[18px] text-muted-foreground">{{
                        $t('inbox.listening.connect_description')
                    }}</span>
                </span>
            </button>
            <div
                class="flex gap-2 rounded-lg bg-muted p-4"
                data-testid="inbox-listening-how"
            >
                <IconInfoCircle class="mt-0.5 size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
                <span class="flex min-w-0 flex-col gap-1">
                    <span class="text-sm leading-[21px] font-medium text-foreground">{{
                        $t('inbox.listening.how_title')
                    }}</span>
                    <span class="text-xs leading-[18px] text-muted-foreground">{{
                        $t(copyKeys.how, { days: String(backfillDays) })
                    }}</span>
                </span>
            </div>
        </div>
    </div>
</template>
