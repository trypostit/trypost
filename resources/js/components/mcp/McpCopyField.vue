<script setup lang="ts">
import { IconCheck, IconCopy } from '@tabler/icons-vue';
import { onBeforeUnmount, ref } from 'vue';

import { Button } from '@/components/ui/button';
import { copyToClipboard } from '@/lib/utils';

const props = defineProps<{
    label: string;
    value: string;
    testid: string;
    /** Keeps the value left-to-right (URLs) in right-to-left locales. */
    ltr?: boolean;
    /** Shows a multi-line value, such as a config file, in a code block. */
    multiline?: boolean;
}>();

const copied = ref(false);
let copiedTimeout: ReturnType<typeof setTimeout> | null = null;

const copyValue = async (): Promise<void> => {
    const didCopy = await copyToClipboard(props.value, undefined, {
        showSuccessToast: false,
    });

    if (!didCopy) {
        return;
    }

    copied.value = true;

    if (copiedTimeout) {
        clearTimeout(copiedTimeout);
    }

    copiedTimeout = setTimeout(() => {
        copied.value = false;
    }, 2000);
};

onBeforeUnmount(() => {
    if (copiedTimeout) {
        clearTimeout(copiedTimeout);
    }
});
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <span class="text-xs font-medium text-muted-foreground">
            {{ label }}
        </span>
        <div
            :class="[
                'flex min-w-0 gap-2 rounded-md border border-input bg-card ps-3 pe-1',
                multiline ? 'items-start py-1' : 'h-9 items-center',
            ]"
        >
            <pre
                v-if="multiline"
                dir="ltr"
                class="min-w-0 flex-1 overflow-x-auto py-1.5 text-start font-mono text-xs leading-5 text-foreground"
                :data-testid="testid"
            ><code>{{ value }}</code></pre>
            <span
                v-else
                :dir="ltr ? 'ltr' : undefined"
                class="min-w-0 flex-1 truncate text-sm text-foreground"
                :data-testid="testid"
                >{{ value }}</span
            >
            <Button
                type="button"
                variant="secondary"
                size="sm"
                class="h-7 shrink-0"
                :data-testid="`${testid}-copy`"
                @click="copyValue"
            >
                <IconCheck v-if="copied" class="size-3.5 text-green-500" />
                <IconCopy v-else class="size-3.5" />
                {{ copied ? $t('mcp.copied') : $t('mcp.copy') }}
            </Button>
        </div>
    </div>
</template>
