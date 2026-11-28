<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconCheck, IconCopy } from '@tabler/icons-vue';
import { computed, onBeforeUnmount, ref } from 'vue';

import ChannelAvatar from '@/components/app/ChannelAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { copyToClipboard } from '@/lib/utils';
import { disconnect } from '@/routes/app/channels';
import { accountTypeKey, type ConnectedAccount } from '@/types/app/social-account';

const props = defineProps<{
    keyword: string;
}>();

const emit = defineEmits<{
    refresh: [channel: ConnectedAccount];
}>();

const channel = ref<ConnectedAccount | null>(null);
const isOpen = ref(false);
const processing = ref(false);
const confirmation = ref('');

const name = computed(() =>
    channel.value ? channel.value.display_name || channel.value.username : '',
);

const typeKey = computed(() =>
    channel.value ? accountTypeKey(channel.value) : null,
);

const isConfirmed = computed(() => confirmation.value.trim() === props.keyword);

const keywordCopied = ref(false);
let keywordCopiedTimeout: ReturnType<typeof setTimeout> | null = null;

const copyKeyword = async (): Promise<void> => {
    const didCopy = await copyToClipboard(props.keyword, undefined, {
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

const open = (target: ConnectedAccount): void => {
    channel.value = target;
    confirmation.value = '';
    processing.value = false;
    isOpen.value = true;
};

const close = (): void => {
    isOpen.value = false;
    confirmation.value = '';
};

const refresh = (): void => {
    if (!channel.value) {
        return;
    }

    const target = channel.value;

    close();
    emit('refresh', target);
};

const submit = (): void => {
    if (!channel.value || !isConfirmed.value || processing.value) {
        return;
    }

    processing.value = true;

    router.delete(disconnect.url(channel.value.id), {
        preserveScroll: true,
        onSuccess: close,
        onFinish: () => {
            processing.value = false;
        },
    });
};

defineExpose({ open, close });
</script>

<template>
    <Dialog :open="isOpen" @update:open="(value) => (value ? null : close())">
        <DialogContent
            v-if="channel"
            :show-close-button="false"
            class="gap-0 p-0 sm:max-w-[495px]"
            data-testid="confirm-delete-modal"
        >
            <form @submit.prevent="submit">
                <div class="flex flex-col gap-4 px-8 pt-7 pb-6">
                    <DialogTitle
                        class="font-sans text-base font-emphasis"
                        data-testid="disconnect-channel-title"
                    >
                        {{
                            $t('channels.disconnect_modal.title_named', {
                                name,
                            })
                        }}
                    </DialogTitle>

                    <div
                        class="flex items-center gap-3 rounded-xl border border-border px-4 py-3.5"
                        data-testid="disconnect-channel-card"
                    >
                        <ChannelAvatar
                            :platform="channel.platform"
                            :src="channel.avatar_url"
                            :name="name"
                            :size="32"
                            ring="background"
                            avatar-class="rounded-md"
                        />
                        <div class="min-w-0">
                            <p
                                class="truncate text-sm leading-5 font-emphasis text-foreground"
                            >
                                {{ name }}
                            </p>
                            <p
                                class="truncate text-sm leading-5 text-muted-foreground"
                            >
                                {{
                                    typeKey
                                        ? $t(typeKey)
                                        : getPlatformLabel(channel.platform)
                                }}
                            </p>
                        </div>
                    </div>

                    <DialogDescription
                        class="text-sm leading-relaxed text-foreground"
                        data-testid="confirm-delete-description"
                    >
                        {{ $t('channels.disconnect_modal.description') }}
                        <strong class="font-emphasis">{{
                            $t('channels.disconnect_modal.irreversible')
                        }}</strong>
                    </DialogDescription>

                    <p class="text-sm leading-relaxed text-foreground">
                        {{ $t('channels.disconnect_modal.refresh_before') }}
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            class="mx-0.5 align-baseline font-emphasis"
                            data-testid="disconnect-channel-refresh"
                            @click="refresh"
                        >
                            {{ $t('channels.refresh_connection') }}
                        </Button>
                        {{ $t('channels.disconnect_modal.refresh_after') }}
                    </p>

                    <div class="mt-2 flex flex-col gap-2">
                        <p
                            class="flex flex-wrap items-center gap-1 text-sm text-muted-foreground"
                        >
                            <span>{{ $t('common.confirm_modal.type') }}</span>
                            <code
                                class="inline-flex items-center gap-1.5 rounded-md border border-border bg-muted px-1.5 py-0.5 font-mono text-xs font-medium break-all text-foreground"
                            >
                                {{ keyword }}
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
                                                    $t(
                                                        'common.confirm_modal.copy_to_clipboard',
                                                    )
                                                }}
                                            </p>
                                        </TooltipContent>
                                    </Tooltip>
                                </TooltipProvider>
                            </code>
                            <span>{{ $t('common.confirm_modal.to_confirm') }}</span>
                        </p>
                        <Input
                            v-model="confirmation"
                            :placeholder="keyword"
                            :aria-label="keyword"
                            autocomplete="off"
                            autofocus
                            data-testid="confirm-delete-input"
                        />
                    </div>
                </div>

                <DialogFooter class="mx-2 mb-2">
                    <Button
                        type="button"
                        variant="ghost"
                        data-testid="confirm-delete-cancel"
                        @click="close"
                    >
                        {{ $t('channels.disconnect_modal.cancel') }}
                    </Button>
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing || !isConfirmed"
                        data-testid="confirm-delete-action"
                    >
                        {{ $t('channels.disconnect_modal.title') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
