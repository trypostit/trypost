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
    <div
        class="json-viewer group relative min-w-0 overflow-hidden rounded-md border border-border"
    >
        <TooltipProvider v-if="serialized" :delay-duration="200">
            <div
                class="absolute top-2 right-2 z-10 opacity-0 transition-opacity duration-150 group-hover:opacity-100 focus-within:opacity-100"
            >
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="inline-flex size-7 items-center justify-center rounded-md border border-border bg-card text-muted-foreground shadow-xs transition hover:bg-accent hover:text-foreground"
                            :aria-label="$t('common.actions.copy')"
                            @click="copyToClipboard(serialized)"
                        >
                            <IconCopy class="size-3.5" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent>{{
                        $t('common.actions.copy')
                    }}</TooltipContent>
                </Tooltip>
            </div>
        </TooltipProvider>
        <pre
            class="json-viewer__body overflow-x-auto px-3 py-2.5 text-xs leading-5"
        ><code class="hljs language-json" v-html="highlighted" /></pre>
    </div>
</template>
