<script setup lang="ts">
import { IconX } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetClose,
    SheetContent,
    SheetTitle,
} from '@/components/ui/sheet';

withDefaults(
    defineProps<{
        title: string;
        testId: string;
        showHeader?: boolean;
        /** The drag handle at the top, for a sheet that has no header. */
        handle?: boolean;
        contentClass?: string;
    }>(),
    { showHeader: true, handle: false, contentClass: '' },
);

const open = defineModel<boolean>('open', { required: true });
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="bottom"
            :show-close-button="false"
            :aria-describedby="undefined"
            class="max-h-[85dvh] gap-0 overflow-y-auto overscroll-contain rounded-t-2xl p-0 pb-[env(safe-area-inset-bottom)]"
            :class="contentClass"
            :data-testid="testId"
            @open-auto-focus.prevent
        >
            <div
                v-if="showHeader"
                class="sticky top-0 z-10 flex shrink-0 items-center justify-between gap-2 bg-background py-2 ps-4 pe-2"
            >
                <SheetTitle class="truncate text-base">{{ title }}</SheetTitle>
                <SheetClose as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :aria-label="$t('common.close')"
                        :data-testid="`${testId}-close`"
                    >
                        <IconX class="size-4" />
                    </Button>
                </SheetClose>
            </div>
            <SheetTitle v-else class="sr-only">{{ title }}</SheetTitle>
            <div
                v-if="handle"
                class="mx-auto mt-3 mb-2 h-1 w-10 shrink-0 rounded-full bg-border-strong"
                aria-hidden="true"
            />
            <slot />
        </SheetContent>
    </Sheet>
</template>
