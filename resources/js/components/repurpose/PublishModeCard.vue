<script setup lang="ts">
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type {
    PublishModeOption,
    RepurposePublishMode,
} from '@/types/repurpose';

defineProps<{
    modes: PublishModeOption[];
}>();

const mode = defineModel<RepurposePublishMode>({ required: true });
</script>

<template>
    <Card data-testid="repurpose-publish-mode-card">
        <CardHeader>
            <CardTitle>{{ $t('repurposes.publish_mode.title') }}</CardTitle>
            <CardDescription>{{
                $t('repurposes.publish_mode.description')
            }}</CardDescription>
        </CardHeader>

        <CardContent class="space-y-2">
            <button
                v-for="option in modes"
                :key="option.value"
                type="button"
                class="flex w-full items-start gap-3 rounded-md border p-3 text-left transition-[color,border-color,background-color,box-shadow] hover:shadow-sm"
                :class="
                    mode === option.value
                        ? 'border-amber-300 bg-amber-100 text-amber-950 shadow-xs'
                        : 'border-border bg-card hover:border-amber-200 hover:bg-amber-50'
                "
                :data-testid="`publish-mode-${option.value}`"
                @click="mode = option.value"
            >
                <span
                    class="mt-0.5 inline-flex size-4 shrink-0 items-center justify-center rounded-full border border-border"
                >
                    <span
                        v-if="mode === option.value"
                        class="size-2 rounded-full bg-foreground"
                    />
                </span>

                <span class="min-w-0">
                    <span class="block text-sm font-bold">{{
                        option.label
                    }}</span>
                    <span class="block text-xs text-muted-foreground">{{
                        option.description
                    }}</span>
                </span>
            </button>
        </CardContent>
    </Card>
</template>
