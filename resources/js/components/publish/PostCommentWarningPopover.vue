<script setup lang="ts">
import { IconAlertTriangle } from '@tabler/icons-vue';

import { Badge } from '@/components/ui/badge';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

defineProps<{
    testKey: string;
}>();
</script>

<template>
    <Popover>
        <PopoverTrigger as-child>
            <button
                type="button"
                class="rounded-full focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                :aria-label="$t('posts.publish.instagram_comment_warning.title')"
                :data-testid="`post-comment-warning-${testKey}`"
            >
                <Badge variant="warning" class="cursor-pointer" data-single-line>
                    <IconAlertTriangle aria-hidden="true" />
                    {{ $t('posts.publish.instagram_comment_warning.badge') }}
                </Badge>
            </button>
        </PopoverTrigger>
        <PopoverContent
            align="start"
            :collision-padding="16"
            class="flex w-80 flex-col gap-2"
            :data-testid="`post-comment-warning-popover-${testKey}`"
        >
            <p class="text-sm font-emphasis text-foreground">
                {{ $t('posts.publish.instagram_comment_warning.title') }}
            </p>
            <p class="text-sm text-foreground">
                {{ $t('posts.publish.instagram_comment_warning.description') }}
            </p>
        </PopoverContent>
    </Popover>
</template>
