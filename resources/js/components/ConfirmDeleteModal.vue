<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconAlertTriangle, IconCopy } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { copyToClipboard } from '@/lib/utils';

const props = defineProps({
    title: {
        type: String,
        default: 'Are you sure?',
    },

    description: {
        type: String,
        default: 'Are you sure you want to perform this action?',
    },

    action: {
        type: String,
        default: 'Delete',
    },

    cancel: {
        type: String,
        default: 'Cancel',
    },

    method: {
        type: String,
        default: 'delete',
    },

    irreversible: {
        type: Boolean,
        default: true,
    },
});

const emit = defineEmits(['deleted', 'closed']);

const isOpen = ref(false);
const processing = ref(false);
const url = ref<string | null>(null);
const confirmInput = ref('');
const confirmText = ref('');

const requiresConfirmation = computed(() => confirmText.value.length > 0);
const isConfirmed = computed(
    () =>
        !requiresConfirmation.value || confirmInput.value === confirmText.value,
);

const remove = () => {
    if (!url.value || !isConfirmed.value) return;

    processing.value = true;

    const options = {
        preserveState: true,
        preserveScroll: true,
        onSuccess: () => {
            close();
            emit('deleted');
        },
        onFinish: () => {
            processing.value = false;
        },
    };

    const method = props.method as 'delete' | 'get' | 'post' | 'put' | 'patch';

    if (method === 'delete' || method === 'get') {
        router[method](url.value, options as any);
    } else {
        router[method](url.value, {}, options as any);
    }
};

const open = (data: { url: string; confirmText?: string }) => {
    url.value = data.url;
    confirmText.value = data.confirmText ?? '';
    processing.value = false;
    confirmInput.value = '';
    isOpen.value = true;
};

const close = () => {
    isOpen.value = false;
    processing.value = false;
    confirmInput.value = '';
    emit('closed');
};

const onOpenChange = (value: boolean) => {
    isOpen.value = value;
    if (!value) {
        close();
    }
};

defineExpose({
    open,
    close,
});
</script>

<template>
    <Dialog :open="isOpen" @update:open="onOpenChange">
        <DialogContent :show-close-button="false" class="sm:max-w-md">
            <DialogHeader class="items-start text-left">
                <div class="flex items-start gap-3">
                    <div
                        class="inline-flex size-12 -rotate-3 shrink-0 items-center justify-center rounded-2xl border-2 border-foreground bg-rose-200 shadow-2xs"
                    >
                        <IconAlertTriangle class="size-6 text-rose-700" stroke-width="2.25" />
                    </div>
                    <div class="flex-1 space-y-1">
                        <DialogTitle>{{ title }}</DialogTitle>
                        <DialogDescription class="space-y-1">
                            <span class="block">{{ description }}</span>
                            <span v-if="irreversible" class="block font-semibold text-rose-700">
                                {{ trans('common.confirm_modal.cannot_be_undone') }}
                            </span>
                        </DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <div v-if="requiresConfirmation" class="space-y-2">
                <p class="flex flex-wrap items-center gap-1 text-sm text-foreground/80">
                    <span>{{ trans('common.confirm_modal.type') }}</span>
                    <code
                        class="inline-flex items-center gap-1.5 rounded-md border-2 border-foreground bg-amber-100 px-1.5 py-0.5 font-mono text-xs font-bold break-all text-foreground shadow-2xs"
                    >
                        {{ confirmText }}
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        tabindex="-1"
                                        class="inline-flex shrink-0 cursor-pointer items-center rounded text-foreground/60 hover:text-foreground"
                                        @click="
                                            copyToClipboard(
                                                confirmText,
                                                trans('common.confirm_modal.copy_to_clipboard'),
                                            )
                                        "
                                    >
                                        <IconCopy class="size-3" />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>{{ trans('common.confirm_modal.copy_to_clipboard') }}</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </code>
                    <span>{{ trans('common.confirm_modal.to_confirm') }}</span>
                </p>
                <Input
                    v-model="confirmInput"
                    autocomplete="off"
                    autofocus
                />
            </div>

            <DialogFooter class="sm:justify-start sm:gap-2">
                <Button
                    variant="destructive"
                    :disabled="processing || !isConfirmed"
                    @click="remove"
                >
                    {{ action }}
                </Button>
                <Button
                    variant="outline"
                    @click="close"
                >
                    {{ cancel }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
