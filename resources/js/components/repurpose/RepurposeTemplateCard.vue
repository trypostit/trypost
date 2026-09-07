<script setup lang="ts">
import RepurposeFlow from '@/components/repurpose/RepurposeFlow.vue';
import { Button } from '@/components/ui/button';
import type { RepurposeTemplate } from '@/types/repurpose';

defineProps<{
    template: RepurposeTemplate;
}>();

const emit = defineEmits<{
    use: [template: RepurposeTemplate];
}>();
</script>

<template>
    <div
        class="group relative flex flex-col items-center gap-3 rounded-xl border-2 border-foreground bg-card p-4 text-center shadow-xs transition-shadow hover:shadow-md"
    >
        <RepurposeFlow
            :source="{ platform: template.source_platform }"
            :destinations="template.destination_platforms.map((platform) => ({ platform }))"
            size="lg"
        />

        <div class="w-full min-w-0 flex-1">
            <span class="block text-sm font-semibold text-foreground">
                {{ $t(`repurposes.templates.${template.key}.title`) }}
            </span>
            <p class="mt-0.5 text-xs leading-tight text-foreground/60">
                {{ $t(`repurposes.templates.${template.key}.description`) }}
            </p>
        </div>

        <Button
            class="w-full"
            :data-testid="`use-template-${template.key}`"
            @click="emit('use', template)"
        >
            {{ $t('repurposes.templates.use') }}
        </Button>
    </div>
</template>
