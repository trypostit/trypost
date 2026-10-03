<script setup lang="ts">
import { IconInfoCircle } from '@tabler/icons-vue';

import SettingsRow from '@/components/posts/editor/SettingsRow.vue';
import SettingsSection from '@/components/posts/editor/SettingsSection.vue';
import { Switch } from '@/components/ui/switch';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

withDefaults(defineProps<{ testId: string; disabled?: boolean }>(), {
    disabled: false,
});

const checked = defineModel<boolean>({ required: true });
</script>

<template>
    <SettingsSection>
        <SettingsRow :label="$t('posts.form.ai_generated.label')">
            <template #label>
                <span class="inline-flex items-center gap-1.5">
                    <span data-single-line>{{
                        $t('posts.form.ai_generated.label')
                    }}</span>
                    <TooltipProvider :delay-duration="150">
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <button
                                    type="button"
                                    class="rounded-full text-muted-foreground"
                                    :data-testid="`${testId}-info`"
                                    :aria-label="
                                        $t('posts.form.ai_generated.hint')
                                    "
                                >
                                    <IconInfoCircle class="size-4" />
                                </button>
                            </TooltipTrigger>
                            <TooltipContent side="bottom">
                                {{ $t('posts.form.ai_generated.hint') }}
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </span>
            </template>
            <div class="flex min-h-8 items-center">
                <Switch
                    v-model="checked"
                    size="sm"
                    :data-testid="testId"
                    :disabled="disabled"
                    :aria-label="$t('posts.form.ai_generated.label')"
                />
            </div>
        </SettingsRow>
    </SettingsSection>
</template>
