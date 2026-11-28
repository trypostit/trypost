<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

interface Option {
    value: string;
    labelKey: string;
    disabledReasonKey?: string;
}

withDefaults(
    defineProps<{
        options: Option[];
        modelValue: string;
        testIdPrefix: string;
        disabled?: boolean;
        error?: string;
    }>(),
    { disabled: false, error: undefined },
);

const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
</script>

<template>
    <div class="flex flex-col gap-1.5" :data-testid="testIdPrefix">
        <RadioGroup
            :model-value="modelValue"
            :disabled="disabled"
            orientation="horizontal"
            :aria-label="$t('posts.create.steps.format_title')"
            class="flex min-h-6 flex-wrap items-center gap-x-5 gap-y-2"
            @update:model-value="emit('update:modelValue', String($event))"
        >
            <template v-for="option in options" :key="option.value">
                <TooltipProvider
                    v-if="option.disabledReasonKey"
                    :delay-duration="150"
                >
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <label
                                :data-testid="`${testIdPrefix}-${option.value}-label`"
                                class="flex cursor-not-allowed items-center gap-2 text-sm text-foreground has-disabled:opacity-50"
                            >
                                <RadioGroupItem
                                    :value="option.value"
                                    disabled
                                    :data-testid="`${testIdPrefix}-${option.value}`"
                                />
                                <span data-single-line>{{
                                    $t(option.labelKey)
                                }}</span>
                            </label>
                        </TooltipTrigger>
                        <TooltipContent side="top">
                            <span
                                :data-testid="`${testIdPrefix}-${option.value}-reason`"
                                >{{ $t(option.disabledReasonKey) }}</span
                            >
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>
                <label
                    v-else
                    :data-testid="`${testIdPrefix}-${option.value}-label`"
                    class="flex cursor-pointer items-center gap-2 text-sm text-foreground has-disabled:cursor-not-allowed has-disabled:opacity-50"
                >
                    <RadioGroupItem
                        :value="option.value"
                        :data-testid="`${testIdPrefix}-${option.value}`"
                    />
                    <span data-single-line>{{ $t(option.labelKey) }}</span>
                </label>
            </template>
        </RadioGroup>
        <InputError :message="error" />
    </div>
</template>
