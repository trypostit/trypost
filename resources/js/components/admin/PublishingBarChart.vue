<script setup lang="ts">
import { computed } from 'vue';

import AdminDailyBarChart, {
    type AdminChartSeries,
} from '@/components/admin/AdminDailyBarChart.vue';
import type { AdminPublishingDay } from '@/types/admin';

const props = defineProps<{
    days: AdminPublishingDay[];
}>();

const dates = computed(() => props.days.map((day) => day.date));

const series = computed((): AdminChartSeries[] => [
    {
        key: 'published',
        label: 'Published',
        color: 'var(--chart-3)',
        values: props.days.map((day) => day.published),
    },
    {
        key: 'failed',
        label: 'Failed',
        color: 'var(--destructive)',
        values: props.days.map((day) => day.failed),
    },
]);
</script>

<template>
    <AdminDailyBarChart
        :days="dates"
        :series="series"
        test-id="admin-publishing-chart"
    />
</template>
