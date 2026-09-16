<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { onMounted, ref, watch } from 'vue';

import MetricsGrid from '@/components/analytics/MetricsGrid.vue';
import { show as showAnalytics } from '@/routes/app/analytics';

interface MetricItem {
    label: string;
    value: number;
}

const props = defineProps<{
    accountId: string;
}>();

const metrics = ref<MetricItem[]>([]);
const isLoading = ref(false);

const http = useHttp<Record<string, never>, { metrics: MetricItem[] }>({});

const fetchMetrics = async () => {
    isLoading.value = true;
    metrics.value = [];

    try {
        const response = await http.get(showAnalytics.url(props.accountId));
        metrics.value = response?.metrics || [];
    } catch {
        metrics.value = [];
    } finally {
        isLoading.value = false;
    }
};

watch(
    () => props.accountId,
    () => {
        fetchMetrics();
    },
);

onMounted(() => {
    fetchMetrics();
});

defineExpose({ supportsDateRange: false });
</script>

<template>
    <MetricsGrid
        :metrics="metrics"
        :loading="isLoading"
        :empty-label="trans('analytics.no_data')"
    />
</template>
