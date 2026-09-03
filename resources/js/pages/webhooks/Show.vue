<script setup lang="ts">
import { Head, InfiniteScroll, router } from '@inertiajs/vue3';
import {
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
import { destroy, replay, update } from '@/routes/app/webhooks';

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

const displaySecret = computed(() => {
    if (secretVisible.value) {
        return props.webhook.signing_secret;
    }

    return `${props.webhook.signing_secret.slice(0, 5)}••••••••••••`;
});

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
    <Head :title="`${$t('webhooks.title')} - ${webhook.endpoint}`" />

    <AppLayout>
        <div class="flex min-h-0 flex-1 flex-col gap-6 px-6 py-8">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="text-xl font-semibold tracking-tight">
                        {{ webhook.endpoint }}
                    </h2>
                    <div class="mt-2 flex items-center gap-2 text-sm text-muted-foreground">
                        <Badge :variant="webhook.status === 'enabled' ? 'default' : 'secondary'">
                            {{ $t(`webhooks.status.${webhook.status}`) }}
                        </Badge>
                        <span v-if="webhook.last_sent_at">&middot;</span>
                        <span v-if="webhook.last_sent_at">{{
                            $t('webhooks.show.last_sent', {
                                time: date.diffForHumans(webhook.last_sent_at),
                            })
                        }}</span>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <span class="text-sm text-muted-foreground">{{
                        $t('webhooks.show.signing_secret')
                    }}</span>
                    <code class="rounded bg-muted px-2 py-1 font-mono text-sm">{{
                        displaySecret
                    }}</code>
                    <TooltipProvider>
                        <Tooltip>
                            <TooltipTrigger as-child>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    class="h-8 w-8"
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
                                    class="h-8 w-8"
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
                            <TooltipContent>{{ $t('webhooks.actions.copy_secret') }}</TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <Button
                                variant="outline"
                                size="icon"
                                data-testid="webhook-actions-trigger"
                            >
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
                                <IconPlayerPlay
                                    v-if="webhook.status !== 'enabled'"
                                    class="size-4"
                                />
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
                                @click="
                                    copyToClipboard(webhook.id, trans('webhooks.copied.id'))
                                "
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
                                        confirmText: webhook.endpoint,
                                    })
                                "
                            >
                                <IconTrash class="size-4" />
                                {{ $t('webhooks.actions.delete') }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </div>

            <div class="flex flex-wrap items-start gap-8">
                <div>
                    <p class="text-xs font-medium uppercase text-muted-foreground">
                        {{ $t('webhooks.show.listening_for') }}
                    </p>
                    <div class="mt-1 flex flex-wrap gap-1">
                        <Badge
                            v-for="event in webhook.events"
                            :key="event"
                            variant="secondary"
                            class="font-mono text-xs"
                        >
                            {{ event }}
                        </Badge>
                    </div>
                </div>
            </div>

            <div
                v-if="logs.data.length > 0"
                class="grid h-[calc(100vh-16.5rem)] grid-cols-3 overflow-hidden rounded-md border"
            >
                <div class="col-span-1 overflow-y-auto border-r">
                    <InfiniteScroll data="logs" preserve-scroll>
                        <button
                            v-for="log in logs.data"
                            :key="log.id"
                            class="relative flex w-full items-center gap-3 border-b px-4 py-3 text-left transition-colors hover:bg-muted/50"
                            :class="selectedLog?.id === log.id ? 'bg-muted' : ''"
                            type="button"
                            @click="
                                selectedLog = log;
                                markSeen(log.id);
                            "
                        >
                            <span
                                v-if="newLogIds.has(log.id)"
                                class="absolute left-1.5 top-1/2 size-1.5 -translate-y-1/2 animate-pulse rounded-full bg-blue-500"
                            />
                            <div
                                class="flex size-6 shrink-0 items-center justify-center rounded-full"
                                :class="
                                    isSuccess(log)
                                        ? 'bg-green-500/10 text-green-600 dark:text-green-400'
                                        : isFailed(log)
                                          ? 'bg-red-500/10 text-red-600 dark:text-red-400'
                                          : 'bg-muted text-muted-foreground'
                                "
                            >
                                <IconCheck v-if="isSuccess(log)" class="size-3.5" />
                                <IconX v-else-if="isFailed(log)" class="size-3.5" />
                                <span v-else class="size-1.5 rounded-full bg-current" />
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate text-sm font-medium">{{ log.event_type }}</p>
                                <p class="text-xs text-muted-foreground">
                                    {{ date.diffForHumans(log.created_at) }}
                                </p>
                            </div>
                        </button>
                    </InfiniteScroll>
                </div>

                <div v-if="selectedLog" class="col-span-2 overflow-y-auto">
                    <div class="space-y-6 p-6">
                        <div class="flex items-center justify-between">
                            <Badge variant="default" class="font-mono">
                                {{ selectedLog.event_type }}
                            </Badge>
                            <Button
                                variant="outline"
                                size="sm"
                                data-testid="replay-log"
                                :disabled="replaying"
                                @click="replayLog(selectedLog)"
                            >
                                {{ $t('webhooks.actions.replay') }}
                            </Button>
                        </div>

                        <div class="grid grid-cols-2 gap-6">
                            <div>
                                <p class="text-xs font-medium uppercase text-muted-foreground">
                                    {{ $t('webhooks.show.http_status') }}
                                </p>
                                <p class="mt-1 text-sm font-medium">
                                    {{ formatStatusCode(selectedLog.response_status) }}
                                </p>
                            </div>
                            <div>
                                <p class="text-xs font-medium uppercase text-muted-foreground">
                                    {{ $t('webhooks.show.attempts') }}
                                </p>
                                <p class="mt-1 text-sm font-medium">{{ selectedLog.attempts }}</p>
                            </div>
                        </div>

                        <div>
                            <p class="text-xs font-medium uppercase text-muted-foreground">
                                {{ $t('webhooks.show.delivered_at') }}
                            </p>
                            <p class="mt-1 text-sm">
                                {{ date.formatDateTime(selectedLog.created_at) }}
                            </p>
                        </div>

                        <div>
                            <p class="text-xs font-medium uppercase text-muted-foreground">
                                {{ $t('webhooks.show.response_body') }}
                            </p>
                            <div v-if="selectedLog.response_body" class="mt-2">
                                <JsonViewer :value="parsedResponseBody" />
                            </div>
                            <p v-else class="mt-2 text-sm text-muted-foreground">
                                {{ $t('webhooks.show.no_response_body') }}
                            </p>
                        </div>

                        <div>
                            <p class="text-xs font-medium uppercase text-muted-foreground">
                                {{ $t('webhooks.show.payload') }}
                            </p>
                            <div class="mt-2">
                                <JsonViewer :value="selectedLog.payload" />
                            </div>
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
