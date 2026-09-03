<script setup lang="ts">
import { Head, InfiniteScroll, Link, router } from '@inertiajs/vue3';
import {
    IconArrowLeft,
    IconCheck,
    IconCopy,
    IconDots,
    IconEye,
    IconEyeOff,
    IconPencil,
    IconPlayerPause,
    IconPlayerPlay,
    IconRefresh,
    IconTrash,
    IconWebhook,
    IconX,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref, watch } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import JsonViewer from '@/components/JsonViewer.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import EditWebhookDialog from '@/components/webhook/EditWebhookDialog.vue';
import RotateSecretDialog from '@/components/webhook/RotateSecretDialog.vue';
import { useWebhookEcho } from '@/composables/echo/useWebhookEcho';
import date from '@/date';
import AppLayout from '@/layouts/AppLayout.vue';
import { copyToClipboard } from '@/lib/utils';
import { destroy, index, replay, update } from '@/routes/app/webhooks';

interface WebhookItem {
    id: string;
    endpoint: string;
    events: string[];
    status: string;
    signing_secret: string;
    last_sent_at: string | null;
}

interface WebhookLogItem {
    id: string;
    event_type: string;
    payload: Record<string, unknown> | null;
    response_status: number | null;
    response_body: string | null;
    delivered_at: string | null;
    failed_at: string | null;
    attempts: number;
    created_at: string;
}

interface ScrollLogs {
    data: WebhookLogItem[];
}

const props = defineProps<{
    webhook: WebhookItem;
    logs: ScrollLogs;
}>();

const newLogIds = reactive(new Set<string>());

useWebhookEcho(
    props.webhook.id,
    '.webhook.log.updated',
    (payload: WebhookLogItem) => {
        const existing = props.logs.data.find((log) => log.id === payload.id);

        if (existing) {
            existing.response_status = payload.response_status;
            existing.response_body = payload.response_body;
            existing.delivered_at = payload.delivered_at;
            existing.failed_at = payload.failed_at;
            existing.attempts = payload.attempts;
        } else {
            props.logs.data.unshift(payload);
            newLogIds.add(payload.id);
        }
    },
);

const markSeen = (logId: string) => {
    newLogIds.delete(logId);
};

const editDialogOpen = ref(false);
const confirmDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);
const rotateSecretDialogOpen = ref(false);
const togglingStatus = ref(false);
const secretVisible = ref(false);
const replaying = ref(false);

const endpointHost = computed(() => {
    try {
        return new URL(props.webhook.endpoint).host;
    } catch {
        return props.webhook.endpoint;
    }
});

const displaySecret = computed(() => {
    if (secretVisible.value) {
        return props.webhook.signing_secret;
    }

    return `${props.webhook.signing_secret.slice(0, 5)}••••••••••••`;
});

const statusVariant = (
    status: string,
): 'default' | 'secondary' | 'warning' => {
    if (status === 'enabled') {
        return 'default';
    }

    if (status === 'paused') {
        return 'warning';
    }

    return 'secondary';
};

const toggleStatus = () => {
    togglingStatus.value = true;
    const newStatus = props.webhook.status === 'enabled' ? 'disabled' : 'enabled';
    router.put(
        update.url(props.webhook),
        { status: newStatus },
        {
            preserveScroll: true,
            onFinish: () => {
                togglingStatus.value = false;
            },
        },
    );
};

const selectedLog = ref<WebhookLogItem | null>(props.logs.data[0] ?? null);

watch(
    () => props.logs.data,
    (data) => {
        if (!selectedLog.value || !data.find((log) => log.id === selectedLog.value!.id)) {
            selectedLog.value = data[0] ?? null;
        }
    },
);

const httpReasonCodes = new Set([
    200, 201, 202, 204, 400, 401, 403, 404, 408, 422, 429, 500, 502, 503, 504,
]);

const formatStatusCode = (code: number | null): string => {
    if (!code) {
        return trans('webhooks.show.no_response');
    }

    const reason = httpReasonCodes.has(code)
        ? trans(`webhooks.http_reasons.${code}`)
        : trans('webhooks.http_reasons.unknown');

    return trans('webhooks.show.status_code', {
        code: String(code),
        reason,
    });
};

const parsedResponseBody = computed((): unknown => {
    if (!selectedLog.value?.response_body) {
        return null;
    }

    try {
        return JSON.parse(selectedLog.value.response_body);
    } catch {
        return selectedLog.value.response_body;
    }
});

const isSuccess = (log: WebhookLogItem): boolean => Boolean(log.delivered_at);
const isFailed = (log: WebhookLogItem): boolean => Boolean(log.failed_at);

const replayLog = (log: WebhookLogItem) => {
    replaying.value = true;
    router.post(
        replay.url({ webhook: props.webhook.id, webhookLog: log.id }),
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                replaying.value = false;
            },
        },
    );
};
</script>

<template>
    <Head :title="`${$t('webhooks.title')} - ${endpointHost}`" />

    <AppLayout>
        <div class="flex min-h-0 flex-1 flex-col gap-6 px-6 py-8">
            <div>
                <Link :href="index.url()">
                    <Button variant="outline">
                        <IconArrowLeft class="size-4" />
                        {{ $t('common.back') }}
                    </Button>
                </Link>
            </div>

            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="flex items-start gap-4">
                    <div
                        class="hidden size-12 shrink-0 -rotate-2 items-center justify-center rounded-2xl border-2 border-foreground bg-violet-100 shadow-2xs sm:inline-flex"
                    >
                        <IconWebhook class="size-6 text-foreground" stroke-width="2" />
                    </div>
                    <div class="min-w-0 space-y-2">
                        <h1
                            class="text-2xl font-semibold leading-tight text-foreground sm:text-4xl"
                            style="font-family: var(--font-display)"
                        >
                            {{ endpointHost }}
                        </h1>
                        <p class="break-all font-mono text-sm text-foreground/70">
                            {{ webhook.endpoint }}
                        </p>
                        <div class="flex flex-wrap items-center gap-2">
                            <Badge :variant="statusVariant(webhook.status)">
                                {{ $t(`webhooks.status.${webhook.status}`) }}
                            </Badge>
                            <span
                                v-if="webhook.last_sent_at"
                                class="text-sm font-medium text-foreground/60"
                            >
                                {{
                                    $t('webhooks.show.last_sent', {
                                        time: date.diffForHumans(webhook.last_sent_at),
                                    })
                                }}
                            </span>
                        </div>
                    </div>
                </div>

                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <Button variant="outline" size="icon" data-testid="webhook-actions-trigger">
                            <IconDots class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            data-testid="edit-webhook-button"
                            @click="editDialogOpen = true"
                        >
                            <IconPencil class="size-4" />
                            {{ $t('webhooks.actions.edit') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem :disabled="togglingStatus" @click="toggleStatus">
                            <IconPlayerPlay v-if="webhook.status !== 'enabled'" class="size-4" />
                            <IconPlayerPause v-else class="size-4" />
                            {{
                                webhook.status === 'enabled'
                                    ? $t('webhooks.actions.disable')
                                    : $t('webhooks.actions.enable')
                            }}
                        </DropdownMenuItem>
                        <DropdownMenuItem @click="rotateSecretDialogOpen = true">
                            <IconRefresh class="size-4" />
                            {{ $t('webhooks.actions.rotate') }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            data-testid="copy-id-button"
                            @click="copyToClipboard(webhook.id, trans('webhooks.copied.id'))"
                        >
                            <IconCopy class="size-4" />
                            {{ $t('webhooks.actions.copy_id') }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            data-testid="delete-webhook-button"
                            @click="
                                confirmDeleteModal?.open({
                                    url: destroy.url(props.webhook),
                                    confirmText: trans('common.confirm_modal.delete_keyword'),
                                })
                            "
                        >
                            <IconTrash class="size-4" />
                            {{ $t('webhooks.actions.delete') }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>

            <div
                class="grid gap-6 rounded-xl border-2 border-foreground bg-card p-4 shadow-2xs sm:p-5 lg:grid-cols-2"
            >
                <div class="space-y-2">
                    <p class="text-sm font-bold text-foreground">
                        {{ $t('webhooks.show.signing_secret') }}
                    </p>
                    <div class="flex items-stretch gap-2">
                        <code
                            class="flex h-10 min-w-0 flex-1 items-center rounded-md border-2 border-foreground bg-background px-3 font-mono text-sm font-bold text-foreground shadow-2xs"
                        >
                            <span class="block truncate">{{ displaySecret }}</span>
                        </code>
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        data-testid="toggle-secret"
                                        @click="secretVisible = !secretVisible"
                                    >
                                        <IconEyeOff v-if="secretVisible" class="size-4" />
                                        <IconEye v-else class="size-4" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {{
                                        secretVisible
                                            ? $t('webhooks.actions.hide_secret')
                                            : $t('webhooks.actions.reveal_secret')
                                    }}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                        <TooltipProvider>
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        data-testid="copy-secret"
                                        @click="
                                            copyToClipboard(
                                                webhook.signing_secret,
                                                trans('webhooks.copied.secret'),
                                            )
                                        "
                                    >
                                        <IconCopy class="size-4" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {{ $t('webhooks.actions.copy_secret') }}
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                </div>

                <div class="space-y-2">
                    <p class="text-sm font-bold text-foreground">
                        {{ $t('webhooks.show.listening_for') }}
                    </p>
                    <div class="flex flex-wrap gap-1.5">
                        <Badge v-for="event in webhook.events" :key="event" variant="outline">
                            {{ event }}
                        </Badge>
                    </div>
                </div>
            </div>

            <div
                v-if="logs.data.length > 0"
                class="grid min-h-0 flex-1 grid-cols-1 overflow-hidden rounded-xl border-2 border-foreground bg-card shadow-2xs lg:h-[calc(100vh-24rem)] lg:grid-cols-3"
            >
                <div class="overflow-y-auto border-b-2 border-foreground lg:border-b-0 lg:border-r-2">
                    <InfiniteScroll data="logs" preserve-scroll>
                        <button
                            v-for="log in logs.data"
                            :key="log.id"
                            class="relative flex w-full items-center gap-3 border-b-2 border-foreground/10 px-4 py-3 text-left transition-colors hover:bg-violet-50 dark:hover:bg-violet-950/30"
                            :class="
                                selectedLog?.id === log.id
                                    ? 'bg-violet-100 dark:bg-violet-950/40'
                                    : ''
                            "
                            type="button"
                            @click="
                                selectedLog = log;
                                markSeen(log.id);
                            "
                        >
                            <span
                                v-if="newLogIds.has(log.id)"
                                class="absolute left-1.5 top-1/2 size-1.5 -translate-y-1/2 animate-pulse rounded-full bg-violet-500"
                            />
                            <div
                                class="flex size-8 shrink-0 items-center justify-center rounded-xl border-2 border-foreground shadow-2xs"
                                :class="
                                    isSuccess(log)
                                        ? 'bg-emerald-200 text-foreground'
                                        : isFailed(log)
                                          ? 'bg-rose-200 text-foreground'
                                          : 'bg-muted text-foreground/60'
                                "
                            >
                                <IconCheck v-if="isSuccess(log)" class="size-3.5" stroke-width="2.5" />
                                <IconX v-else-if="isFailed(log)" class="size-3.5" stroke-width="2.5" />
                                <span v-else class="size-1.5 rounded-full bg-current" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-bold text-foreground">
                                    {{ log.event_type }}
                                </p>
                                <p class="text-xs font-medium text-foreground/60">
                                    {{ date.diffForHumans(log.created_at) }}
                                </p>
                            </div>
                        </button>
                    </InfiniteScroll>
                </div>

                <div v-if="selectedLog" class="overflow-y-auto lg:col-span-2">
                    <div class="space-y-6 p-5 sm:p-6">
                        <div class="flex items-center justify-between gap-3">
                            <Badge variant="default">
                                {{ selectedLog.event_type }}
                            </Badge>
                            <Button
                                variant="outline"
                                size="sm"
                                data-testid="replay-log"
                                :disabled="replaying"
                                @click="replayLog(selectedLog)"
                            >
                                <IconRefresh class="size-4" />
                                {{ $t('webhooks.actions.replay') }}
                            </Button>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <div
                                class="rounded-xl border-2 border-foreground bg-background p-3 shadow-2xs"
                            >
                                <p class="text-sm font-bold text-foreground">
                                    {{ $t('webhooks.show.http_status') }}
                                </p>
                                <p class="mt-1 text-sm font-medium text-foreground/80">
                                    {{ formatStatusCode(selectedLog.response_status) }}
                                </p>
                            </div>
                            <div
                                class="rounded-xl border-2 border-foreground bg-background p-3 shadow-2xs"
                            >
                                <p class="text-sm font-bold text-foreground">
                                    {{ $t('webhooks.show.attempts') }}
                                </p>
                                <p class="mt-1 text-sm font-medium text-foreground/80">
                                    {{ selectedLog.attempts }}
                                </p>
                            </div>
                            <div
                                class="rounded-xl border-2 border-foreground bg-background p-3 shadow-2xs"
                            >
                                <p class="text-sm font-bold text-foreground">
                                    {{ $t('webhooks.show.delivered_at') }}
                                </p>
                                <p class="mt-1 text-sm font-medium text-foreground/80">
                                    {{ date.formatDateTime(selectedLog.created_at) }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <p class="text-sm font-bold text-foreground">
                                {{ $t('webhooks.show.response_body') }}
                            </p>
                            <div v-if="selectedLog.response_body">
                                <JsonViewer :value="parsedResponseBody" />
                            </div>
                            <p v-else class="text-sm text-foreground/60">
                                {{ $t('webhooks.show.no_response_body') }}
                            </p>
                        </div>

                        <div class="space-y-2">
                            <p class="text-sm font-bold text-foreground">
                                {{ $t('webhooks.show.payload') }}
                            </p>
                            <JsonViewer :value="selectedLog.payload" />
                        </div>
                    </div>
                </div>
            </div>

            <EmptyState
                v-else
                :icon="IconWebhook"
                :title="$t('webhooks.show.empty_title')"
                :description="$t('webhooks.show.empty_description')"
            />

            <EditWebhookDialog v-model:open="editDialogOpen" :webhook="webhook" />
            <RotateSecretDialog
                v-model:open="rotateSecretDialogOpen"
                :webhook-id="webhook.id"
            />
            <ConfirmDeleteModal
                ref="confirmDeleteModal"
                :title="$t('webhooks.delete.title')"
                :description="$t('webhooks.delete.description')"
                :action="$t('webhooks.delete.confirm')"
                :cancel="$t('webhooks.delete.cancel')"
            />
        </div>
    </AppLayout>
</template>
