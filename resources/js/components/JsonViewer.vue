<script setup lang="ts">
import { IconCopy } from '@tabler/icons-vue';
import hljs from 'highlight.js/lib/core';
import jsonLang from 'highlight.js/lib/languages/json';
import { computed } from 'vue';

import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { copyToClipboard } from '@/lib/utils';

hljs.registerLanguage('json', jsonLang);

const props = defineProps<{ value: unknown }>();

const serialized = computed(() => {
    if (props.value === null || props.value === undefined) return '';
    try {
        return JSON.stringify(props.value, null, 2);
    } catch {
        return String(props.value);
    }
});

const highlighted = computed(() => {
    if (serialized.value === '') return '';
    return hljs.highlight(serialized.value, { language: 'json' }).value;
});
</script>

<template>
    <div class="json-viewer group relative overflow-hidden rounded-lg border-2 border-foreground">
        <TooltipProvider v-if="serialized" :delay-duration="200">
            <div
                class="absolute right-2 top-2 z-10 opacity-0 transition-opacity duration-150 group-hover:opacity-100 focus-within:opacity-100"
            >
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="inline-flex size-7 items-center justify-center rounded-md border-2 border-foreground bg-card shadow-[1px_1px_0_var(--foreground)] transition hover:-translate-x-px hover:-translate-y-px hover:shadow-[2px_2px_0_var(--foreground)] active:translate-x-0 active:translate-y-0 active:shadow-[0_0_0_var(--foreground)]"
                            :aria-label="$t('common.actions.copy')"
                            @click="copyToClipboard(serialized)"
                        >
                            <IconCopy class="size-3.5" stroke-width="2.5" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>{{ $t('common.actions.copy') }}</TooltipContent>
                </Tooltip>
            </div>
        </TooltipProvider>
        <pre class="json-viewer__body overflow-x-auto p-3 text-xs leading-relaxed"><code class="hljs language-json" v-html="highlighted" /></pre>
    </div>
</template>
