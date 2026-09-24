<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconCopy } from '@tabler/icons-vue';
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
    if (!url.value || !isConfirmed.value) {
        return;
    }

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
        <DialogContent class="sm:max-w-md" data-testid="confirm-delete-modal">
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription class="space-y-1">
                    <span class="block">{{ description }}</span>
                    <span class="block font-medium text-destructive">
                        {{ trans('common.confirm_modal.cannot_be_undone') }}
                    </span>
                </DialogDescription>
            </DialogHeader>

            <div v-if="requiresConfirmation" class="space-y-2">
                <p
                    class="flex flex-wrap items-center gap-1 text-sm text-muted-foreground"
                >
                    <span>{{ trans('common.confirm_modal.type') }}</span>
                    <code
                        class="inline-flex items-center gap-1.5 rounded-md border border-border bg-muted px-1.5 py-0.5 font-mono text-xs font-medium break-all text-foreground"
                    >
                        {{ confirmText }}
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <button
                                        type="button"
                                        tabindex="-1"
                                        class="inline-flex shrink-0 cursor-pointer items-center rounded text-muted-foreground hover:text-foreground"
                                        @click="
                                            copyToClipboard(
                                                confirmText,
                                                trans(
                                                    'common.confirm_modal.copy_to_clipboard',
                                                ),
                                            )
                                        "
                                    >
                                        <IconCopy class="size-3" />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>
                                        {{
                                            trans(
                                                'common.confirm_modal.copy_to_clipboard',
                                            )
                                        }}
                                    </p>
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
                    data-testid="confirm-delete-input"
                />
            </div>

            <DialogFooter>
                <Button
                    variant="outline"
                    data-testid="confirm-delete-cancel"
                    @click="close"
                >
                    {{ cancel }}
                </Button>
                <Button
                    variant="destructive"
                    data-testid="confirm-delete-action"
                    :disabled="processing || !isConfirmed"
                    @click="remove"
                >
                    {{ action }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
