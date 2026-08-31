<script setup lang="ts">
import { router, useHttp } from '@inertiajs/vue3';
import { IconCheck, IconCopy, IconLoader2 } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { onUnmounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useWorkspaceEcho } from '@/composables/echo/useWorkspaceEcho';
import dayjs from '@/dayjs';
import { copyToClipboard } from '@/lib/utils';
import { connect as connectTelegram } from '@/routes/app/social/telegram';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    reconnectId?: string;
}>();

type Phase = 'loading' | 'ready' | 'connected' | 'expired' | 'error';

interface ConnectResponse {
    code: string;
    nonce: string;
    bot_username: string;
    expires_at: string;
}

const SUCCESS_CLOSE_DELAY_MS = 1200;

const phase = ref<Phase>('loading');
const code = ref('');
const nonce = ref('');
const botUsername = ref('');
const errorMessage = ref('');

const httpConnect = useHttp<Record<string, never>, ConnectResponse>({});

let expiryTimer: ReturnType<typeof setTimeout> | null = null;

const clearExpiry = () => {
    if (expiryTimer !== null) {
        clearTimeout(expiryTimer);
        expiryTimer = null;
    }
};

// The channel is linked server-side by the webhook; Reverb pushes the result here.
useWorkspaceEcho<{ nonce: string }>(
    '.telegram.channel.connected',
    (payload) => {
        if (phase.value !== 'ready' || payload.nonce !== nonce.value) {
            return;
        }

        phase.value = 'connected';
        clearExpiry();
        toast.success(trans('accounts.telegram.connected_toast'));
        setTimeout(() => {
            open.value = false;
            router.reload();
        }, SUCCESS_CLOSE_DELAY_MS);
    },
);

const KNOWN_CONNECT_ERRORS = ['network_taken', 'wrong_chat', 'busy'];

useWorkspaceEcho<{ nonce: string; reason: string }>(
    '.telegram.connect.failed',
    (payload) => {
        if (phase.value !== 'ready' || payload.nonce !== nonce.value) {
            return;
        }

        phase.value = 'error';
        clearExpiry();
        errorMessage.value = trans(
            KNOWN_CONNECT_ERRORS.includes(payload.reason)
                ? `accounts.telegram.${payload.reason}`
                : 'accounts.telegram.error_generic',
        );
    },
);

const start = async () => {
    phase.value = 'loading';
    errorMessage.value = '';

    try {
        const response = await httpConnect.post(
            connectTelegram.url(
                props.reconnectId
                    ? { query: { reconnect: props.reconnectId } }
                    : undefined,
            ),
        );
        code.value = response.code;
        nonce.value = response.nonce;
        botUsername.value = response.bot_username;
        phase.value = 'ready';

        clearExpiry();
        expiryTimer = setTimeout(
            () => {
                if (phase.value === 'ready') {
                    phase.value = 'expired';
                }
            },
            Math.max(0, dayjs(response.expires_at).diff(dayjs())),
        );
    } catch (error) {
        phase.value = 'error';
        errorMessage.value =
            (error as { response?: { data?: { message?: string } } })?.response
                ?.data?.message ?? trans('accounts.telegram.error_generic');
    }
};

const copyCommand = () => {
    copyToClipboard(
        `/connect ${code.value}`,
        trans('accounts.telegram.copied_toast'),
    );
};

watch(open, (isOpen) => {
    if (isOpen) {
        start();
    } else {
        clearExpiry();
    }
});

onUnmounted(clearExpiry);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <div class="flex items-start gap-3">
                    <img
                        src="/images/accounts/telegram.png"
                        alt="Telegram"
                        class="size-10 rounded-lg"
                    />
                    <div class="text-left">
                        <DialogTitle>{{
                            $t('accounts.telegram.title')
                        }}</DialogTitle>
                        <DialogDescription>{{
                            $t('accounts.telegram.description')
                        }}</DialogDescription>
                    </div>
                </div>
            </DialogHeader>

            <div
                v-if="phase === 'loading'"
                class="flex items-center justify-center py-10"
            >
                <IconLoader2
                    class="size-6 animate-spin text-muted-foreground"
                />
            </div>

            <div v-else-if="phase === 'error'" class="space-y-4 py-2">
                <p class="text-sm text-destructive">{{ errorMessage }}</p>
                <Button class="w-full" @click="start">{{
                    $t('accounts.telegram.retry')
                }}</Button>
            </div>

            <div v-else-if="phase === 'expired'" class="space-y-4 py-2">
                <p class="text-sm text-muted-foreground">
                    {{ $t('accounts.telegram.expired') }}
                </p>
                <Button class="w-full" @click="start">{{
                    $t('accounts.telegram.new_code')
                }}</Button>
            </div>

            <div
                v-else-if="phase === 'connected'"
                class="flex flex-col items-center gap-3 py-8 text-center"
            >
                <span
                    class="inline-flex size-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600"
                >
                    <IconCheck class="size-6" stroke-width="3" />
                </span>
                <p class="text-sm font-medium">
                    {{ $t('accounts.telegram.connected') }}
                </p>
            </div>

            <div v-else class="min-w-0 space-y-5 py-2">
                <ol class="space-y-4 text-sm">
                    <li class="flex gap-3">
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-foreground text-xs font-semibold"
                            >1</span
                        >
                        <span>{{
                            trans('accounts.telegram.step_admin', {
                                bot: `@${botUsername}`,
                            })
                        }}</span>
                    </li>
                    <li class="flex gap-3">
                        <span
                            class="flex size-6 shrink-0 items-center justify-center rounded-full border-2 border-foreground text-xs font-semibold"
                            >2</span
                        >
                        <div class="min-w-0 flex-1 space-y-2">
                            <span>{{
                                $t('accounts.telegram.step_command')
                            }}</span>
                            <TooltipProvider>
                                <Tooltip>
                                    <TooltipTrigger as-child>
                                        <button
                                            type="button"
                                            class="group flex w-full cursor-pointer items-center justify-between gap-2 rounded-lg border bg-muted px-3 py-2 text-left font-mono text-sm transition-colors hover:bg-muted/70"
                                            @click="copyCommand"
                                        >
                                            <span class="min-w-0 truncate"
                                                >/connect {{ code }}</span
                                            >
                                            <IconCopy
                                                class="size-4 shrink-0 text-muted-foreground group-hover:text-foreground"
                                            />
                                        </button>
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        <p>
                                            {{
                                                $t(
                                                    'accounts.telegram.copy_tooltip',
                                                )
                                            }}
                                        </p>
                                    </TooltipContent>
                                </Tooltip>
                            </TooltipProvider>
                        </div>
                    </li>
                </ol>

                <div
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <IconLoader2 class="size-4 animate-spin" />
                    {{ $t('accounts.telegram.waiting') }}
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
