<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconArrowLeft, IconCircleCheck, IconCircleDot, IconCircleX } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';

import AutomationMobileBackHeader from '@/components/automations/AutomationMobileBackHeader.vue';
import AutomationTabsNav from '@/components/automations/AutomationTabsNav.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { index as automationsIndex } from '@/routes/app/automations';
import type { Automation } from '@/types/automation/automation';

defineProps<{
    automation: Automation;
    current: 'workflow' | 'invocations' | 'metrics' | 'settings';
}>();

const statusConfig = (status: string) => {
    const configs: Record<string, { icon: typeof IconCircleDot; label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
        draft: { icon: IconCircleDot, label: trans('automations.status.draft'), variant: 'outline' },
        active: { icon: IconCircleCheck, label: trans('automations.status.active'), variant: 'default' },
        paused: { icon: IconCircleX, label: trans('automations.status.paused'), variant: 'secondary' },
    };
    return configs[status] ?? configs['draft'];
};
</script>

<template>
    <div class="flex-shrink-0">
        <AutomationMobileBackHeader />

        <header class="hidden items-center justify-between gap-4 border-b-2 border-foreground/10 bg-card px-4 py-2 lg:flex">
            <div class="flex min-w-0 items-center gap-3">
                <Link :href="automationsIndex.url()">
                    <Button variant="outline" size="icon-sm">
                        <IconArrowLeft class="size-4" />
                    </Button>
                </Link>
                <div class="flex min-w-0 items-center gap-3">
                    <h1 class="truncate text-lg font-semibold">{{ automation.name }}</h1>
                    <Badge :variant="statusConfig(automation.status).variant" class="shrink-0">
                        <component :is="statusConfig(automation.status).icon" class="size-3" />
                        {{ statusConfig(automation.status).label }}
                    </Badge>
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <slot name="actions" />
            </div>
        </header>

        <AutomationTabsNav :automation-id="automation.id" :current="current" />
    </div>
</template>
