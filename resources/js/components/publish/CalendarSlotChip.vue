<script setup lang="ts">
import { computed } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import dayjs from '@/dayjs';
import type { CalendarSlot, PublishSocialAccount } from '@/types/publish';

const props = defineProps<{
    postingSlot: CalendarSlot;
    channel: PublishSocialAccount | null;
    timezone: string;
    canCreatePost: boolean;
}>();

const time = computed(() =>
    date.formatTimeInTimezone(props.postingSlot.at, props.timezone),
);

const testKey = computed(
    () => `${props.postingSlot.channel_id}-${dayjs.utc(props.postingSlot.at).unix()}`,
);

const newPost = (): void => {
    openPostComposer({
        socialAccountIds: [props.postingSlot.channel_id],
        queueSlot: props.postingSlot.at,
    });
};
</script>

<template>
    <component
        :is="canCreatePost ? 'button' : 'div'"
        :type="canCreatePost ? 'button' : undefined"
        class="group/slot flex h-7 w-full min-w-0 shrink-0 items-center gap-1.5 rounded-lg border border-dashed border-border-strong px-1.5 text-xs font-medium text-muted-foreground transition-control enabled:hover:bg-secondary enabled:hover:text-foreground enabled:focus-visible:bg-secondary enabled:focus-visible:text-foreground"
        :title="channel?.display_label"
        :aria-label="
            channel
                ? $t('posts.publish.slot_aria', {
                      channel: channel.display_label,
                      network: getPlatformLabel(channel.platform),
                      time,
                  })
                : undefined
        "
        :data-testid="`calendar-posting-slot-${testKey}`"
        @click="canCreatePost ? newPost() : undefined"
    >
        <PlatformBrandIcon
            v-if="channel"
            :platform="channel.platform"
            class="shrink-0"
            :data-testid="`calendar-posting-slot-icon-${testKey}`"
        />
        <span
            :class="[
                'truncate',
                canCreatePost
                    ? 'group-hover/slot:hidden group-focus-visible/slot:hidden'
                    : '',
            ]"
            >{{ time }}</span
        >
        <span
            v-if="canCreatePost"
            class="hidden truncate group-hover/slot:inline group-focus-visible/slot:inline"
            >{{ $t('posts.publish.add_post_in_slot') }}</span
        >
    </component>
</template>
