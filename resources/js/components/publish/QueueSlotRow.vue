<script setup lang="ts">
import { IconPlus } from '@tabler/icons-vue';
import { computed, watch } from 'vue';

import PlatformBrandIcon from '@/components/PlatformBrandIcon.vue';
import { useDisplayTimezone } from '@/composables/useDisplayTimezone';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import dayjs from '@/dayjs';
import type { PublishSocialAccount, QueueItem } from '@/types/publish';

const props = defineProps<{
    item: QueueItem;
    channel: PublishSocialAccount | null;
    displayTimezone: string;
    dropTarget?: boolean;
}>();

const { canCreatePost } = useWorkspaceAbilities();
const { timezone, formatTime } = useDisplayTimezone(props.displayTimezone, []);

watch(
    () => props.displayTimezone,
    (value) => {
        timezone.value = value;
    },
);

const testKey = computed(
    () => `${props.item.channel_id}-${dayjs.utc(props.item.at).unix()}`,
);

const newPost = (): void => {
    openPostComposer({
        socialAccountIds: [props.item.channel_id],
        queueSlot: props.item.at,
    });
};
</script>

<template>
    <div
        class="group/slot grid grid-cols-[4.5rem_minmax(0,1fr)] items-center gap-x-4 md:grid-cols-[71px_minmax(0,1fr)] md:gap-x-8"
        :data-testid="`queue-slot-${testKey}`"
        :data-drop-target="dropTarget ? '' : undefined"
    >
        <time
            class="text-sm font-medium text-foreground"
            :datetime="item.at"
        >
            {{ formatTime(item.at) }}
        </time>
        <component
            :is="canCreatePost ? 'button' : 'div'"
            :type="canCreatePost ? 'button' : undefined"
            class="flex h-12 min-w-0 items-center gap-1 rounded-xl border border-border-strong bg-card px-2 text-sm leading-none font-medium text-foreground transition-[background-color] duration-200 enabled:cursor-pointer enabled:hover:bg-secondary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring group-data-drop-target/slot:border-primary-strong group-data-drop-target/slot:bg-secondary"
            :aria-label="
                channel
                    ? $t('posts.publish.slot_aria', {
                          channel: channel.display_label,
                          network: getPlatformLabel(channel.platform),
                          time: formatTime(item.at),
                      })
                    : undefined
            "
            :title="channel?.display_label"
            :data-testid="canCreatePost ? `queue-slot-new-${testKey}` : undefined"
            @click="canCreatePost ? newPost() : undefined"
        >
            <PlatformBrandIcon
                v-if="channel"
                :platform="channel.platform"
                colored
                class="shrink-0"
                :data-testid="`queue-slot-icon-${testKey}`"
            />
            <template v-if="canCreatePost">
                <IconPlus class="size-4 shrink-0" />
                {{ $t('posts.publish.new_in_slot') }}
            </template>
            <span v-else class="truncate font-normal text-muted-foreground">
                {{ channel?.display_label }}
            </span>
        </component>
    </div>
</template>
