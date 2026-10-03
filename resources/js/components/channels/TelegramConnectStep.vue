<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconCircleCheck,
    IconCopy,
    IconExternalLink,
    IconLoader2,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted, onUnmounted, ref } from 'vue';

import ConnectHelpMenu, {
    type ConnectHelpLink,
} from '@/components/channels/ConnectHelpMenu.vue';
import PlatformLogo from '@/components/PlatformLogo.vue';
import { Button } from '@/components/ui/button';
import { DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { useWorkspaceEcho } from '@/composables/echo/useWorkspaceEcho';
import dayjs from '@/dayjs';
import { copyToClipboard } from '@/lib/utils';
import { connect as connectTelegram } from '@/routes/app/social/telegram';

const props = defineProps<{
    reconnectId?: string;
}>();

const emit = defineEmits<{
    connected: [{ accountId: string; created: boolean }];
}>();

type Phase = 'loading' | 'ready' | 'expired' | 'error';

interface ConnectResponse {
    code: string;
    nonce: string;
    bot_username: string;
    bot_url: string | null;
    expires_at: string;
}

const COPIED_RESET_DELAY_MS = 2000;
const BOT_PLACEHOLDER = '{bot}';

const HELP_LINKS: readonly ConnectHelpLink[] = [
    {
        label: 'accounts.telegram.help.channel_admins',
        testId: 'telegram-connect-help-channel-admins',
        href: 'https://telegram.org/faq_channels#q-what-can-administrators-do',
    },
    {
        label: 'accounts.telegram.help.group_admins',
        testId: 'telegram-connect-help-group-admins',
        href: 'https://telegram.org/faq#q-can-i-assign-administrators',
    },
    {
        label: 'accounts.telegram.help.bot_privacy',
        testId: 'telegram-connect-help-bot-privacy',
        href: 'https://telegram.org/faq#q-if-i-add-a-bot-to-my-group-can-it-read-my-messages',
    },
];

const phase = ref<Phase>('loading');
const code = ref('');
const nonce = ref('');
const botUsername = ref('');
const botUrl = ref<string | null>(null);
const errorMessage = ref('');
const copied = ref(false);

const httpConnect = useHttp<Record<string, never>, ConnectResponse>({});

let expiryTimer: ReturnType<typeof setTimeout> | null = null;
let copiedTimer: ReturnType<typeof setTimeout> | null = null;
let isUnmounted = false;

const clearExpiry = () => {
    if (expiryTimer !== null) {
        clearTimeout(expiryTimer);
        expiryTimer = null;
    }
};

useWorkspaceEcho<{ nonce: string; account_id: string; created: boolean }>(
    '.telegram.channel.connected',
    (payload) => {
        if (phase.value !== 'ready' || payload.nonce !== nonce.value) {
            return;
        }

        clearExpiry();
        nonce.value = '';
        emit('connected', {
            accountId: payload.account_id,
            created: Boolean(payload.created),
        });
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
    code.value = '';
    copied.value = false;

    try {
        const response = await httpConnect.post(
            connectTelegram.url(
                props.reconnectId
                    ? { query: { reconnect: props.reconnectId } }
                    : undefined,
            ),
        );
        if (isUnmounted) {
            return;
        }

        code.value = response.code;
        nonce.value = response.nonce;
        botUsername.value = response.bot_username;
        botUrl.value = response.bot_url;
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

const command = computed(() => `/connect ${code.value}`);


const canRegenerate = computed(() =>
    ['ready', 'expired', 'error'].includes(phase.value),
);

const splitAroundBot = (text: string): [string, string] => {
    const [before, ...after] = text.split(BOT_PLACEHOLDER);

    return [before, after.join('')];
};

const copyCommand = async () => {
    const didCopy = await copyToClipboard(command.value, undefined, {
        showSuccessToast: false,
    });

    if (!didCopy) {
        return;
    }

    copied.value = true;

    if (copiedTimer !== null) {
        clearTimeout(copiedTimer);
    }

    copiedTimer = setTimeout(() => {
        copied.value = false;
    }, COPIED_RESET_DELAY_MS);
};

onMounted(start);

onUnmounted(() => {
    isUnmounted = true;
    clearExpiry();

    if (copiedTimer !== null) {
        clearTimeout(copiedTimer);
        copiedTimer = null;
    }
});
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div class="min-h-0 flex-1 overflow-y-auto">
            <div
                class="mx-auto flex w-full max-w-[720px] flex-col gap-8 px-4 pt-6 pb-8 sm:px-6"
            >
                <header class="flex flex-col gap-2 text-center">
                    <DialogTitle
                        class="font-sans text-xl leading-tight font-medium text-foreground"
                    >
                        {{ $t('accounts.telegram.title') }}
                    </DialogTitle>
                    <DialogDescription class="text-sm text-muted-foreground">
                        {{ $t('accounts.telegram.description') }}
                    </DialogDescription>
                </header>

                <section
                    class="mx-auto flex w-full max-w-[500px] min-w-0 flex-col gap-4 rounded-xl border border-border bg-card p-6"
                    data-testid="telegram-connect-steps"
                >
                    <h3
                        class="flex items-center gap-2 text-lg leading-tight font-medium text-foreground"
                    >
                        <PlatformLogo
                            platform="telegram"
                            :size="16"
                            :title="null"
                            aria-hidden="true"
                        />
                        {{ $t('accounts.telegram.steps') }}
                    </h3>

                    <div
                        v-if="phase === 'loading'"
                        class="flex items-center justify-center py-8"
                        data-testid="telegram-connect-loading"
                    >
                        <IconLoader2
                            class="size-5 animate-spin text-muted-foreground"
                            aria-hidden="true"
                        />
                    </div>

                    <template v-else>
                        <ol v-if="code" class="flex flex-col gap-3">
                            <li
                                class="flex gap-3 text-sm leading-[21px] text-foreground"
                            >
                                <span
                                    class="flex size-5 shrink-0 items-center justify-center rounded-full border border-border-strong text-xs font-medium"
                                    aria-hidden="true"
                                    >1</span
                                >
                                <span class="min-w-0">
                                    {{
                                        splitAroundBot(
                                            $t('accounts.telegram.step_admin', {
                                                bot: BOT_PLACEHOLDER,
                                            }),
                                        )[0]
                                    }}<strong
                                        v-if="botUsername"
                                        class="font-medium"
                                        data-testid="telegram-connect-bot"
                                        >@{{ botUsername }}</strong
                                    >{{
                                        splitAroundBot(
                                            $t('accounts.telegram.step_admin', {
                                                bot: BOT_PLACEHOLDER,
                                            }),
                                        )[1]
                                    }}
                                    <a
                                        v-if="botUrl"
                                        :href="botUrl"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 text-primary-text underline underline-offset-2 hover:text-primary-text-hover"
                                        data-testid="telegram-connect-open-bot"
                                    >
                                        {{ $t('accounts.telegram.open_bot') }}
                                        <IconExternalLink
                                            class="size-3.5"
                                            aria-hidden="true"
                                        />
                                    </a>
                                </span>
                            </li>
                            <li
                                class="flex gap-3 text-sm leading-[21px] text-foreground"
                            >
                                <span
                                    class="flex size-5 shrink-0 items-center justify-center rounded-full border border-border-strong text-xs font-medium"
                                    aria-hidden="true"
                                    >2</span
                                >
                                <div class="flex min-w-0 flex-1 flex-col gap-2">
                                    <span>{{
                                        $t('accounts.telegram.step_command')
                                    }}</span>
                                    <div
                                        class="flex min-w-0 items-center gap-2 rounded-lg border border-border bg-muted p-1.5 ps-3"
                                    >
                                        <code
                                            class="min-w-0 flex-1 overflow-x-auto py-1 font-mono text-xs leading-5 whitespace-nowrap text-foreground select-all"
                                            data-testid="telegram-connect-command"
                                            >{{ command }}</code
                                        >
                                        <span
                                            v-if="copied"
                                            class="flex h-7 shrink-0 items-center gap-1 px-2 text-sm font-medium text-success-text"
                                            role="status"
                                            data-testid="telegram-connect-copied"
                                        >
                                            <IconCircleCheck
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                            {{ $t('common.actions.copied') }}
                                        </span>
                                        <Button
                                            v-else
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="shrink-0"
                                            :aria-label="
                                                $t(
                                                    'accounts.telegram.copy_command',
                                                )
                                            "
                                            :disabled="phase !== 'ready'"
                                            data-testid="telegram-connect-copy"
                                            @click="copyCommand"
                                        >
                                            <IconCopy
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                            {{ $t('common.actions.copy') }}
                                        </Button>
                                    </div>
                                </div>
                            </li>
                        </ol>

                        <div
                            :class="code ? 'border-t border-border pt-4' : ''"
                            aria-live="polite"
                        >
                            <p
                                v-if="phase === 'ready'"
                                class="flex items-center gap-2 text-sm text-muted-foreground"
                                data-testid="telegram-connect-waiting"
                            >
                                <IconLoader2
                                    class="size-4 shrink-0 animate-spin"
                                    aria-hidden="true"
                                />
                                {{ $t('accounts.telegram.waiting') }}
                            </p>
                            <p
                                v-else-if="phase === 'expired'"
                                class="flex items-start gap-2 rounded-lg border border-warning/30 bg-warning/10 p-3 text-sm text-foreground"
                                data-testid="telegram-connect-expired"
                            >
                                <IconAlertTriangle
                                    class="mt-0.5 size-4 shrink-0 text-warning"
                                    aria-hidden="true"
                                />
                                {{ $t('accounts.telegram.expired') }}
                            </p>
                            <p
                                v-else
                                class="flex items-start gap-2 rounded-lg border border-destructive/30 bg-destructive/10 p-3 text-sm text-destructive-text"
                                role="alert"
                                data-testid="telegram-connect-error"
                            >
                                <IconAlertTriangle
                                    class="mt-0.5 size-4 shrink-0"
                                    aria-hidden="true"
                                />
                                {{ errorMessage }}
                            </p>
                        </div>
                    </template>
                </section>
            </div>
        </div>

        <footer
            class="flex shrink-0 items-center justify-between gap-3 border-t border-border px-4 py-4 sm:px-6"
        >
            <ConnectHelpMenu
                :links="HELP_LINKS"
                test-id="telegram-connect-help"
            />
            <Button
                v-if="canRegenerate"
                size="lg"
                :variant="phase === 'ready' ? 'outline' : 'default'"
                data-testid="telegram-connect-regenerate"
                @click="start"
            >
                {{ $t('accounts.telegram.new_command') }}
            </Button>
        </footer>
    </div>
</template>
