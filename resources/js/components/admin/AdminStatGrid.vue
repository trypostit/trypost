<script setup lang="ts">
/**
 * A row of numbers inside an `AnalyticsSection` (`flush`), drawn like the
 * Insights summary cards: a muted label over a large figure.
 */
import AdminStatTile from '@/components/admin/AdminStatTile.vue';
import type { AdminStat } from '@/types/admin';

defineProps<{
    stats: AdminStat[];
}>();
</script>

<template>
    <div
        class="grid grid-cols-2 border-t border-border md:grid-cols-[repeat(auto-fit,minmax(0,1fr))]"
    >
        <div
            v-for="stat in stats"
            :key="stat.key"
            class="flex min-w-0 flex-col gap-2 px-4 py-4 shadow-[1px_0_0_var(--border),0_1px_0_var(--border)] sm:px-5"
            :data-testid="`admin-stat-${stat.key}`"
        >
            <AdminStatTile
                :label="stat.label"
                :value="stat.value"
                :hint="stat.hint"
                :change="stat.change"
                value-class="text-foreground"
                :value-test-id="`admin-stat-${stat.key}-value`"
            />
        </div>
    </div>
</template>
