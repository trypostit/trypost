<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

import { invocations, metrics, settings, workflow } from '@/routes/app/automations';

const props = defineProps<{
    automationId: string;
    current: 'workflow' | 'invocations' | 'metrics' | 'settings';
}>();

const tabs = computed(() => [
    { key: 'workflow' as const, label: 'automations.nav.workflow', href: workflow.url(props.automationId) },
    { key: 'invocations' as const, label: 'automations.nav.invocations', href: invocations.url(props.automationId) },
    { key: 'metrics' as const, label: 'automations.nav.metrics', href: metrics.url(props.automationId) },
    { key: 'settings' as const, label: 'automations.nav.settings', href: settings.url(props.automationId) },
]);
</script>

<template>
    <nav class="flex items-center gap-4 overflow-x-auto border-b-2 border-foreground/10 px-4 lg:gap-6 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        <Link
            v-for="tab in tabs"
            :key="tab.key"
            :href="tab.href"
            :dusk="`automation-tab-${tab.key}`"
            class="-mb-0.5 shrink-0 whitespace-nowrap border-b-2 py-2.5 text-sm font-medium transition-colors"
            :class="
                tab.key === current
                    ? 'border-primary text-foreground'
                    : 'border-transparent text-foreground/55 hover:text-foreground'
            "
        >
            {{ $t(tab.label) }}
        </Link>
    </nav>
</template>
