<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted, ref, watch } from 'vue';

import MetricsGrid from '@/components/analytics/MetricsGrid.vue';
import dayjs from '@/dayjs';
import { show as showAnalytics } from '@/routes/app/analytics';

interface MetricItem {
    label: string;
    value: number;
}

interface SearchKeyword {
    keyword: string;
    value: number;
    estimated: boolean;
}

const props = defineProps<{
    accountId: string;
    dateRange: { start: Date; end: Date };
}>();

const metrics = ref<MetricItem[]>([]);
const keywords = ref<SearchKeyword[]>([]);
const isLoading = ref(false);

const http = useHttp<
    Record<string, never>,
    { metrics: MetricItem[]; keywords?: SearchKeyword[] }
>({});

// Google only aggregates keywords by month, so the panel names the months it
// actually got rather than echoing the day range the user picked.
const keywordPeriod = computed(() => {
    const start = dayjs(props.dateRange.start).format('MMM YYYY');
    const end = dayjs(props.dateRange.end).format('MMM YYYY');

    return start === end ? start : `${start} – ${end}`;
});

const fetchMetrics = async () => {
    isLoading.value = true;
    metrics.value = [];
    keywords.value = [];

    try {
        const response = await http.get(showAnalytics.url(props.accountId, {
            query: {
                since: dayjs(props.dateRange.start).format('YYYY-MM-DD'),
                until: dayjs(props.dateRange.end).format('YYYY-MM-DD'),
            },
        }));
        metrics.value = response?.metrics || [];
        keywords.value = response?.keywords || [];
    } catch {
        metrics.value = [];
        keywords.value = [];
    } finally {
        isLoading.value = false;
    }
};

watch(() => props.accountId, () => {
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
    <div class="space-y-6">
        <MetricsGrid :metrics="metrics" :loading="isLoading" :empty-label="trans('analytics.no_data')" />

        <section v-if="!isLoading && keywords.length" data-testid="gbp-search-keywords">
            <header class="mb-3 flex items-baseline justify-between gap-2">
                <h3 class="text-sm font-semibold">
                    {{ trans('analytics.search_keywords.title') }}
                </h3>
                <span class="text-xs text-muted-foreground">{{ keywordPeriod }}</span>
            </header>

            <ul class="divide-y-2 divide-foreground/10 overflow-hidden rounded-lg border-2 border-foreground/10">
                <li
                    v-for="keyword in keywords"
                    :key="keyword.keyword"
                    class="flex items-center justify-between gap-4 px-4 py-2.5 text-sm"
                >
                    <span class="truncate">{{ keyword.keyword }}</span>
                    <span
                        class="shrink-0 font-semibold tabular-nums"
                        :title="keyword.estimated ? trans('analytics.search_keywords.estimated') : undefined"
                    >{{ keyword.estimated ? `<${keyword.value}` : keyword.value }}</span>
                </li>
            </ul>
        </section>
    </div>
</template>
