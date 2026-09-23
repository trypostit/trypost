<script setup lang="ts">
import { computed } from 'vue';

import PublicationMetrics from '@/components/analytics/workspace/PublicationMetrics.vue';
import type {
    PublicationAnalyticsDetail,
    UnsupportedPublicationAnalytics,
} from '@/components/analytics/workspace/types';

const props = defineProps<{
    detail?: PublicationAnalyticsDetail | UnsupportedPublicationAnalytics;
}>();
const savedDetail = computed((): PublicationAnalyticsDetail | null =>
    props.detail && 'available' in props.detail && props.detail.available
        ? props.detail
        : null,
);
</script>

<template>
    <div v-if="savedDetail" class="border-t border-border px-4 py-4">
        <h3 class="mb-3 text-sm font-semibold">
            {{ $t('posts.show.metrics') }}
        </h3>
        <PublicationMetrics :detail="savedDetail" />
    </div>
</template>
