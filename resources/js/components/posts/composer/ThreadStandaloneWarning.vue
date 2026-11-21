<script setup lang="ts">
import { computed } from 'vue';

import WarningLine from '@/components/WarningLine.vue';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { threadStandaloneKey } from '@/lib/threadReplies';
import type { ContentTypeValue } from '@/types/content-type';
import type { PlatformValue } from '@/types/platform';

/** Why a card's thread cannot go out because its post type stands alone (`STANDALONE_CONTENT_TYPES`). */
const props = withDefaults(
    defineProps<{
        platform: PlatformValue;
        contentType?: ContentTypeValue | null;
        meta?: Record<string, any> | null;
        testId: string;
    }>(),
    { contentType: null, meta: null },
);

const warningKey = computed(() =>
    threadStandaloneKey(props.contentType, props.meta),
);

const networkLabel = computed(() => getPlatformLabel(props.platform));
</script>

<template>
    <WarningLine v-if="warningKey" :data-testid="testId">
        {{ $t(warningKey, { network: networkLabel }) }}
    </WarningLine>
</template>
