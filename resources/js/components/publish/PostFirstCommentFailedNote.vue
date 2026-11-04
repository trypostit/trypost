<script setup lang="ts">
import { IconMessageOff } from '@tabler/icons-vue';

import { getPlatformLabel } from '@/composables/usePlatformLogo';

defineProps<{
    platform: string | null;
    testId: string;
}>();
</script>

<template>
    <p
        class="flex items-center gap-1.5 text-xs text-muted-foreground"
        :data-testid="testId"
    >
        <IconMessageOff class="size-3.5 shrink-0" />
        <span>{{
            $t('posts.first_comment.failed', {
                network: getPlatformLabel(platform ?? ''),
            })
        }}</span>
    </p>
</template>
