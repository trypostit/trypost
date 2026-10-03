<script setup lang="ts">
import { IconInfoCircle } from '@tabler/icons-vue';

import ContentTypeRadioGroup from '@/components/posts/editor/ContentTypeRadioGroup.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    GOOGLE_BUSINESS_TOPIC_TYPES,
    type GoogleBusinessTopicTypeValue,
} from '@/lib/googleBusiness';

withDefaults(
    defineProps<{
        modelValue: GoogleBusinessTopicTypeValue;
        disabled?: boolean;
    }>(),
    { disabled: false },
);

const emit = defineEmits<{
    'update:modelValue': [value: GoogleBusinessTopicTypeValue];
}>();
</script>

<template>
    <div class="flex min-w-0 flex-1 items-center gap-3">
        <ContentTypeRadioGroup
            :options="[...GOOGLE_BUSINESS_TOPIC_TYPES]"
            :model-value="modelValue"
            test-id-prefix="google-business-topic"
            :disabled="disabled"
            @update:model-value="
                emit(
                    'update:modelValue',
                    $event as GoogleBusinessTopicTypeValue,
                )
            "
        />
        <TooltipProvider :delay-duration="150">
            <Tooltip>
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        class="ms-auto rounded-full text-muted-foreground"
                        data-testid="google-business-topic-help"
                        :aria-label="
                            $t('posts.form.google_business.topic_type_label')
                        "
                    >
                        <IconInfoCircle class="size-4" />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="bottom" class="max-w-80 space-y-2">
                    <p
                        v-for="type in GOOGLE_BUSINESS_TOPIC_TYPES"
                        :key="type.value"
                    >
                        <strong>{{ $t(type.labelKey) }}:</strong>
                        {{
                            $t(
                                `posts.form.google_business.topic_type_help.${type.value.toLowerCase()}`,
                            )
                        }}
                    </p>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    </div>
</template>
