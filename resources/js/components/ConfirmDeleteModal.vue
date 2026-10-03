<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconCheck, IconCopy } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref } from 'vue';

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

    actionTestId: {
        type: String,
        default: 'confirm-delete-action',
    },
});

const emit = defineEmits(['deleted', 'closed']);

const isOpen = ref(false);
const processing = ref(false);
const url = ref<string | null>(null);
const confirmInput = ref('');
const confirmText = ref('');
const payload = ref<Record<string, unknown>>({});
const request = ref<(() => Promise<void>) | null>(null);

const keywordCopied = ref(false);
let keywordCopiedTimeout: ReturnType<typeof setTimeout> | null = null;

const copyKeyword = async () => {
    const didCopy = await copyToClipboard(confirmText.value, undefined, {
        showSuccessToast: false,
    });

    if (!didCopy) {
        return;
    }

    keywordCopied.value = true;

    if (keywordCopiedTimeout) {
        clearTimeout(keywordCopiedTimeout);
    }

    keywordCopiedTimeout = setTimeout(() => {
        keywordCopied.value = false;
    }, 2000);
};

onBeforeUnmount(() => {
    if (keywordCopiedTimeout) {
        clearTimeout(keywordCopiedTimeout);
    }
});

const requiresConfirmation = computed(() => confirmText.value.length > 0);
const isConfirmed = computed(
    () =>
        !requiresConfirmation.value || confirmInput.value.trim() === confirmText.value,
);

const remove = () => {
    if (!url.value || !isConfirmed.value) {
        return;
    }

    processing.value = true;

    if (request.value) {
        request.value()
            .then(() => {
                close();
                emit('deleted');
            })
            .catch(() => undefined)
            .finally(() => {
                processing.value = false;
            });

        return;
    }

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
        router[method](
            url.value,
            (Object.keys(payload.value).length
                ? { ...options, data: payload.value }
                : options) as any,
        );
    } else {
        router[method](url.value, payload.value as any, options as any);
    }
};

const open = (data: {
    url: string;
    confirmText?: string;
    data?: Record<string, unknown>;
    request?: () => Promise<void>;
}) => {
    url.value = data.url;
    payload.value = data.data ?? {};
    request.value = data.request ?? null;
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
                <DialogDescription data-testid="confirm-delete-description">{{
                    description
                }}</DialogDescription>
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
                                        data-testid="confirm-delete-copy-keyword"
                                        @click="copyKeyword"
                                    >
                                        <IconCheck
                                            v-if="keywordCopied"
                                            class="size-3 text-success-text"
                                            data-testid="confirm-delete-keyword-copied"
                                        />
                                        <IconCopy v-else class="size-3" />
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
                    variant="ghost"
                    data-testid="confirm-delete-cancel"
                    @click="close"
                >
                    {{ cancel }}
                </Button>
                <Button
                    variant="destructive"
                    :data-testid="actionTestId"
                    :disabled="processing || !isConfirmed"
                    @click="remove"
                >
                    {{ action }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
