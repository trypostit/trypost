<script setup lang="ts">
import { IconPlus } from '@tabler/icons-vue';
import { computed } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import dayjs from '@/dayjs';
import type { CalendarSlot, PublishSocialAccount } from '@/types/publish';

const props = withDefaults(
    defineProps<{
        postingSlot: CalendarSlot;
        channel: PublishSocialAccount | null;
        timezone: string;
        canCreatePost: boolean;
        layout?: 'chip' | 'agenda';
        compact?: boolean;
    }>(),
    { layout: 'chip', compact: false },
);

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
        v-if="layout === 'agenda'"
        :type="canCreatePost ? 'button' : undefined"
        class="flex h-10 w-full min-w-0 shrink-0 items-center gap-2 rounded-lg border border-dashed border-border-strong px-2.5 text-start text-sm text-muted-foreground transition-control enabled:hover:bg-secondary enabled:hover:text-foreground enabled:focus-visible:outline-2 enabled:focus-visible:outline-offset-1 enabled:focus-visible:outline-ring enabled:active:bg-secondary"
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
        <span class="shrink-0 text-xs font-medium tabular-nums">{{
            time
        }}</span>
        <span
            v-if="channel"
            class="min-w-0 flex-1 truncate text-xs text-subtle-foreground"
            >{{ channel.display_label }}</span
        >
        <span
            v-if="canCreatePost"
            class="ms-auto flex shrink-0 items-center gap-1 text-xs font-medium text-foreground"
        >
            <IconPlus class="size-3.5" aria-hidden="true" />
            {{ $t('posts.publish.add_post_in_slot') }}
        </span>
    </component>
    <component
        :is="canCreatePost ? 'button' : 'div'"
        v-else-if="compact"
        :type="canCreatePost ? 'button' : undefined"
        class="flex size-7 shrink-0 items-center justify-center rounded-lg border border-dashed border-border-strong text-muted-foreground transition-control enabled:hover:bg-secondary enabled:focus-visible:bg-secondary"
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
            class="size-3.5"
            :data-testid="`calendar-posting-slot-icon-${testKey}`"
        />
    </component>
    <component
        v-else
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
