<script setup lang="ts">
import { IconAlertTriangle, IconCircleCheck, IconCopy } from '@tabler/icons-vue';
import { computed, onBeforeUnmount, ref } from 'vue';


import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { copyToClipboard } from '@/lib/utils';

interface Props {
    apiKey: string;
}

const props = defineProps<Props>();

const open = defineModel<boolean>('open', { default: false });

const closeDialog = (): void => {
    open.value = false;
};

const copied = ref(false);
let copiedTimeout: ReturnType<typeof setTimeout> | null = null;

const VISIBLE_CHARS = 4;
const MASK = '•'.repeat(64);

const visibleTail = computed(() => props.apiKey.slice(-VISIBLE_CHARS));

const copyKey = async () => {
    const didCopy = await copyToClipboard(props.apiKey, undefined, {
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
    <Dialog v-model:open="open">
        <DialogContent
            class="sm:max-w-md"
            :show-close-button="false"
            data-testid="api-key-generated-dialog"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('settings.api_keys.generated_dialog.title')
                }}</DialogTitle>
            </DialogHeader>

            <div class="grid min-w-0 grid-cols-1 gap-2">
                <Label for="generated-api-key">{{
                    $t('settings.api_keys.generated_dialog.label')
                }}</Label>
                <div class="flex items-center gap-2">
                    <div
                        id="generated-api-key"
                        data-testid="api-key-generated-input"
                        class="flex h-8 min-w-0 flex-1 items-center rounded-md border border-input bg-card px-2 font-mono text-sm text-foreground select-none dark:bg-input/30"
                    >
                        <span class="min-w-0 flex-1 overflow-hidden whitespace-nowrap">{{
                            MASK
                        }}</span>
                        <span class="shrink-0">{{ visibleTail }}</span>
                    </div>
                    <span
                        v-if="copied"
                        class="flex h-8 shrink-0 items-center gap-1 px-1 text-sm font-medium text-success-text"
                        data-testid="api-key-generated-copied"
                        role="status"
                    >
                        <IconCircleCheck class="size-4" />
                        {{ $t('common.actions.copied') }}
                    </span>
                    <Button
                        v-else
                        type="button"
                        variant="ghost"
                        size="icon"
                        class="shrink-0 text-muted-foreground"
                        data-testid="api-key-generated-copy"
                        :aria-label="$t('settings.api_keys.copy')"
                        @click="copyKey"
                    >
                        <IconCopy class="size-4" />
                    </Button>
                </div>
            </div>

            <div
                class="flex items-start gap-3 rounded-xl border border-warning/30 bg-warning/10 p-3 text-sm text-foreground"
            >
                <IconAlertTriangle class="mt-0.5 size-4 shrink-0 text-warning" />
                <div class="grid gap-0.5">
                    <p class="font-emphasis">
                        {{
                            $t('settings.api_keys.generated_dialog.warning_title')
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        {{
                            $t('settings.api_keys.generated_dialog.warning_body')
                        }}
                    </p>
                </div>
            </div>

            <DialogFooter>
                <Button
                    type="button"
                    data-testid="api-key-generated-done"
                    @click="closeDialog"
                >
                    {{ $t('settings.api_keys.generated_dialog.done') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
