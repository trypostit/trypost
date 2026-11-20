<script setup lang="ts">
import { createReusableTemplate } from '@vueuse/core';

import BottomSheet from '@/components/BottomSheet.vue';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useBelowBreakpoint } from '@/composables/useBreakpoint';

withDefaults(
    defineProps<{
        title: string;
        testId: string;
        contentClass?: string;
        sheetClass?: string;
        align?: 'start' | 'center' | 'end';
    }>(),
    { contentClass: '', sheetClass: '', align: 'end' },
);

const open = defineModel<boolean>('open', { default: false });

const [DefineContent, ReuseContent] = createReusableTemplate();
const asSheet = useBelowBreakpoint('sm');

const close = (): void => {
    open.value = false;
};
</script>

<template>
    <DefineContent>
        <slot :sheet="asSheet" :close="close" />
    </DefineContent>

    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <slot name="trigger" :open="open" />
        </PopoverTrigger>
        <PopoverContent
            v-if="!asSheet"
            :align="align"
            :class="contentClass"
            :data-testid="testId"
        >
            <ReuseContent />
        </PopoverContent>
    </Popover>

    <BottomSheet
        v-if="asSheet"
        v-model:open="open"
        :title="title"
        :show-header="false"
        handle
        :test-id="testId"
        :content-class="sheetClass"
    >
        <ReuseContent />
    </BottomSheet>
</template>
