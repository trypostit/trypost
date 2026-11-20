<script setup lang="ts">
import { IconAlertTriangle } from '@tabler/icons-vue';
import { computed } from 'vue';

import TransSlots from '@/components/TransSlots.vue';
import { Badge } from '@/components/ui/badge';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import type { YouTubeThumbnailWarning } from '@/types/publish';

/** Where YouTube verifies a channel, which unlocks custom thumbnails. */
const YOUTUBE_VERIFY_URL = 'https://www.youtube.com/verify';
const YOUTUBE_VERIFY_LABEL = 'youtube.com/verify';

const props = defineProps<{
    warning: YouTubeThumbnailWarning;
    testKey: string;
}>();

/** Why YouTube kept its own thumbnail, and where the user can set the cover instead. */
const reasonKey = computed(
    () => `posts.publish.youtube_thumbnail_warning.${props.warning}`,
);
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="rounded-full focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                :aria-label="$t('posts.publish.youtube_thumbnail_warning.title')"
                :data-testid="`post-cover-warning-${testKey}`"
            >
                <Badge variant="warning" class="cursor-pointer" data-single-line>
                    <IconAlertTriangle aria-hidden="true" />
                    {{ $t('posts.publish.youtube_thumbnail_warning.badge') }}
                </Badge>
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            :collision-padding="16"
            class="flex w-80 flex-col gap-2"
            :data-testid="`post-cover-warning-popover-${testKey}`"
        >
            <p class="text-sm font-emphasis text-foreground">
                {{ $t('posts.publish.youtube_thumbnail_warning.title') }}
            </p>
            <p
                class="text-sm text-foreground"
                :data-testid="`post-cover-warning-reason-${testKey}`"
            >
                <TransSlots :message="$t(reasonKey)">
                    <template #url>
                        <a
                            :href="YOUTUBE_VERIFY_URL"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="font-medium underline underline-offset-2"
                            :data-testid="`post-cover-warning-verify-${testKey}`"
                            v-text="YOUTUBE_VERIFY_LABEL"
                        />
                    </template>
                </TransSlots>
            </p>
        </PopoverContent>
    </Popover>
</template>
