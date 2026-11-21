<script setup lang="ts">
import { computed } from 'vue';

import ComposerCardWarning from '@/components/posts/composer/ComposerCardWarning.vue';
import { threadStandaloneKey } from '@/lib/threadReplies';
import type { ContentTypeValue } from '@/types/content-type';

/** Why a card's thread cannot go out because its post type stands alone (`STANDALONE_CONTENT_TYPES`). */
const props = withDefaults(
    defineProps<{
        contentType?: ContentTypeValue | null;
        meta?: Record<string, any> | null;
    }>(),
    { contentType: null, meta: null },
);

const warningKey = computed(() =>
    threadStandaloneKey(props.contentType, props.meta),
);
</script>

<template>
    <ComposerCardWarning
        v-if="warningKey"
        data-testid="thread-standalone-warning"
    >
        {{ $t(warningKey) }}
    </ComposerCardWarning>
</template>
