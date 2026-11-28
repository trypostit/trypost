<script setup lang="ts">
import { computed } from 'vue';

import WarningLine from '@/components/app/WarningLine.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { threadUnavailableKey } from '@/lib/app/threadReplies';
import type { ContentTypeValue } from '@/types/app/content-type';
import type { PlatformValue } from '@/types/app/platform';

const props = withDefaults(
    defineProps<{
        platform: PlatformValue;
        contentType?: ContentTypeValue | null;
        meta?: Record<string, any> | null;
        repliesEnabled: boolean;
        testId: string;
    }>(),
    { contentType: null, meta: null },
);

const warningKey = computed(() =>
    threadUnavailableKey(
        props.platform,
        props.contentType,
        props.meta,
        props.repliesEnabled,
    ),
);

const networkLabel = computed(() => getPlatformLabel(props.platform));
</script>

<template>
    <WarningLine v-if="warningKey" :data-testid="testId">
        {{ $t(warningKey, { network: networkLabel }) }}
    </WarningLine>
</template>
