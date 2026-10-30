<script setup lang="ts">
import { IconPhotoShare } from '@tabler/icons-vue';
import { ref, shallowRef } from 'vue';

import ShareChartDialog from '@/components/analytics/ShareChartDialog.vue';
import { Button } from '@/components/ui/button';
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
        <Button
            type="button"
            variant="outline"
            :title="$t('analytics.share.button')"
            :data-testid="testId"
            @click="openDialog"
        >
            <IconPhotoShare aria-hidden="true" />
            {{ $t('analytics.share.action') }}
        </Button>
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
