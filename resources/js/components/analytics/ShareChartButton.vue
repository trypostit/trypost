<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { IconShare2 } from '@tabler/icons-vue';
import { computed, onMounted, ref, shallowRef } from 'vue';

import ShareChartDialog from '@/components/analytics/ShareChartDialog.vue';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    preloadShareCards,
    type ShareMetricKey,
    type ShareMetricOption,
} from '@/lib/insightsShareCard';
import { whenIdle } from '@/lib/utils';

const props = defineProps<{
    /** The channel the numbers belong to; the current workspace when left out. */
    subject?: string;
    range: string;
    /** Builds what the card can show; runs on open, so its labels read the loaded locale. */
    build: () => ShareMetricOption[];
    initialKey?: ShareMetricKey;
    testId: string;
}>();

const page = usePage();
const shareSubject = computed(
    (): string => props.subject ?? page.props.auth.currentWorkspace?.name ?? '',
);
const open = ref(false);
const options = shallowRef<ShareMetricOption[]>([]);

/** The card's fonts and channel photos load while the page is idle, so the first preview paints at once. */
const preloaded = ref(false);

onMounted(() => {
    whenIdle(async () => {
        await preloadShareCards(props.build());
        preloaded.value = true;
    });
});

const openDialog = (): void => {
    options.value = props.build();
    open.value = true;
};
</script>

<template>
    <div class="contents">
        <TooltipProvider :delay-duration="300">
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        class="shrink-0"
                        :aria-label="$t('analytics.share.button')"
                        :data-testid="testId"
                        :data-state="preloaded ? 'ready' : 'loading'"
                        @click="openDialog"
                    >
                        <IconShare2 aria-hidden="true" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>{{ $t('analytics.share.button') }}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
        <ShareChartDialog
            v-model:open="open"
            :subject="shareSubject"
            :range="range"
            :options="options"
            :initial-key="initialKey"
        />
    </div>
</template>
