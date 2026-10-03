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

const selectMode = (value: RepurposePublishMode): void => {
    mode.value = value;
};

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
                class="flex w-full cursor-pointer items-start gap-3 rounded-lg border p-3 text-left transition-control focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                :class="
                    mode === option.value
                        ? 'border-primary-strong bg-primary-subtle text-foreground'
                        : 'border-border-strong bg-card hover:bg-accent'
                "
                :data-testid="`publish-mode-${option.value}`"
                @click="selectMode(option.value)"
            >
                <span
                    class="mt-0.5 inline-flex size-4 shrink-0 items-center justify-center rounded-full border"
                    :class="
                        mode === option.value
                            ? 'border-primary-strong bg-primary-strong'
                            : 'border-input bg-card'
                    "
                >
                    <span
                        v-if="mode === option.value"
                        class="size-1.5 rounded-full bg-white"
                    />
                </span>

                <span class="min-w-0">
                    <span class="block text-sm leading-tight font-emphasis">{{
                        option.label
                    }}</span>
                    <span class="mt-1 block text-sm text-muted-foreground">{{
                        option.description
                    }}</span>
                </span>
            </button>
        </CardContent>
    </Card>
</template>
