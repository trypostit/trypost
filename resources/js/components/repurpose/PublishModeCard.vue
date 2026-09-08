<script setup lang="ts">
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import type { PublishModeOption, RepurposePublishMode } from '@/types/repurpose';

defineProps<{
    modes: PublishModeOption[];
}>();

const mode = defineModel<RepurposePublishMode>({ required: true });
</script>

<template>
    <Card data-testid="repurpose-publish-mode-card">
        <CardHeader>
            <CardTitle>{{ $t('repurposes.publish_mode.title') }}</CardTitle>
            <CardDescription>{{ $t('repurposes.publish_mode.description') }}</CardDescription>
        </CardHeader>

        <CardContent class="space-y-2">
            <button
                v-for="option in modes"
                :key="option.value"
                type="button"
                class="flex w-full items-start gap-3 rounded-xl border-2 p-3 text-left transition-shadow hover:shadow-md"
                :class="mode === option.value ? 'border-foreground bg-emerald-50' : 'border-foreground/20 bg-card'"
                :data-testid="`publish-mode-${option.value}`"
                @click="mode = option.value"
            >
                <span
                    class="mt-0.5 inline-flex size-4 shrink-0 items-center justify-center rounded-full border-2 border-foreground"
                >
                    <span v-if="mode === option.value" class="size-2 rounded-full bg-foreground" />
                </span>

                <span class="min-w-0">
                    <span class="block text-sm font-bold">{{ option.label }}</span>
                    <span class="block text-xs text-muted-foreground">{{ option.description }}</span>
                </span>
            </button>
        </CardContent>
    </Card>
</template>
