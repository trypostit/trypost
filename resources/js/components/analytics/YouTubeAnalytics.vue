<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { onMounted, ref, watch } from 'vue';

import MetricsGrid from '@/components/analytics/MetricsGrid.vue';
import dayjs from '@/dayjs';
import { show as showAnalytics } from '@/routes/app/analytics';

interface MetricItem {
    label: string;
    value: number;
}

const props = defineProps<{
    accountId: string;
    locationId?: string | null;
    dateRange: { start: Date; end: Date };
}>();

const metrics = ref<MetricItem[]>([]);
const isLoading = ref(false);

const http = useHttp<Record<string, never>, { metrics: MetricItem[] }>({});

const fetchMetrics = async () => {
    isLoading.value = true;
    metrics.value = [];

    try {
        const response = await http.get(showAnalytics.url(props.accountId, {
            query: {
                since: dayjs(props.dateRange.start).format('YYYY-MM-DD'),
                until: dayjs(props.dateRange.end).format('YYYY-MM-DD'),
                location_id: props.locationId ?? undefined,
            },
        }));
        metrics.value = response?.metrics || [];
    } catch {
        metrics.value = [];
    } finally {
        isLoading.value = false;
    }
};

watch(() => [props.accountId, props.locationId], () => {
    fetchMetrics();
});

watch(() => props.dateRange, () => {
    fetchMetrics();
}, { deep: true });

onMounted(() => {
    fetchMetrics();
});

defineExpose({ supportsDateRange: true });
</script>

<template>
    <MetricsGrid :metrics="metrics" :loading="isLoading" :empty-label="trans('analytics.no_data')" />
</template>
