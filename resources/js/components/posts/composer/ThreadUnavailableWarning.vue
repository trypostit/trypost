<script setup lang="ts">
import { computed } from 'vue';

import WarningLine from '@/components/WarningLine.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { threadUnavailableKey } from '@/lib/threadReplies';
import type { ContentTypeValue } from '@/types/content-type';
import type { PlatformValue } from '@/types/platform';

/** Why a card's thread cannot go out: replies are off on its network, or its post type stands alone (`threadUnavailableKey()`). */
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
    threadUnavailableKey(props.contentType, props.meta, props.repliesEnabled),
);

const networkLabel = computed(() => getPlatformLabel(props.platform));
</script>

<template>
    <WarningLine v-if="warningKey" :data-testid="testId">
        {{ $t(warningKey, { network: networkLabel }) }}
    </WarningLine>
</template>
