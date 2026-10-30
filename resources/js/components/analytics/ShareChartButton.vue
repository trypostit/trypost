<script setup lang="ts">
import { IconPhotoShare } from '@tabler/icons-vue';
import { ref, shallowRef } from 'vue';

import ShareChartDialog from '@/components/analytics/ShareChartDialog.vue';
import type { ShareMetricOption } from '@/lib/insightsShareCard';

const props = defineProps<{
    variant: 'chart' | 'summary';
    subject: string;
    range: string;
    /** Builds what the card can show; runs on open, so its labels read the loaded locale. */
    build: () => ShareMetricOption[];
    initialKey?: string;
    testId: string;
}>();

const open = ref(false);
const options = shallowRef<ShareMetricOption[]>([]);

const openDialog = (): void => {
    options.value = props.build();
    open.value = true;
};
</script>

<template>
    <div class="contents">
        <button
            type="button"
            class="inline-flex size-8 cursor-pointer items-center justify-center rounded-md text-muted-foreground transition-control hover:bg-accent hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
            :aria-label="$t('analytics.share.button')"
            :title="$t('analytics.share.button')"
            :data-testid="testId"
            @click="openDialog"
        >
            <IconPhotoShare class="size-4" aria-hidden="true" />
        </button>
        <ShareChartDialog
            v-model:open="open"
            :variant="variant"
            :subject="subject"
            :range="range"
            :options="options"
            :initial-key="initialKey"
        />
    </div>
</template>
