<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconRefresh } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import JsonViewer from '@/components/JsonViewer.vue';
import { Button } from '@/components/ui/button';
import date from '@/date';
import { replay } from '@/routes/app/webhooks';
import type { WebhookLog } from '@/types/webhook';

import { webhookEventLabel } from './webhook-events';

const props = defineProps<{
    webhookId: string;
    log: WebhookLog;
}>();

const replaying = ref(false);

const httpReasonCodes = new Set([
    200, 201, 202, 204, 400, 401, 403, 404, 408, 422, 429, 500, 502, 503, 504,
]);

const httpReason = (code: number): string =>
    httpReasonCodes.has(code)
        ? trans(`webhooks.http_reasons.${code}`)
        : trans('webhooks.http_reasons.unknown');

const parsedResponseBody = computed((): unknown => {
    if (!props.log.response_body) {
        return null;
    }

    try {
        return JSON.parse(props.log.response_body);
    } catch {
        return props.log.response_body;
    }
});

const replayLog = () => {
    replaying.value = true;
    router.post(
        replay.url({ webhook: props.webhookId, webhookLog: props.log.id }),
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
    <div
        class="min-h-0 min-w-0 flex-1 overflow-y-auto overscroll-contain"
        data-testid="webhook-log-detail"
    >
        <div
            class="flex items-center justify-between gap-3 border-b border-border px-4 py-3 lg:px-6"
        >
            <h2 class="truncate text-sm font-medium text-foreground">
                {{ webhookEventLabel(log.event_type) }}
            </h2>
            <Button
                variant="outline"
                size="sm"
                data-testid="replay-log"
                :disabled="replaying"
                @click="replayLog"
            >
                <IconRefresh class="size-4" />
                {{ $t('webhooks.actions.replay') }}
            </Button>
        </div>

        <dl
            class="grid grid-cols-2 gap-x-6 gap-y-3 border-b border-border px-4 py-3 text-sm sm:grid-cols-3 lg:px-6"
            data-testid="webhook-log-meta"
        >
            <div class="min-w-0">
                <dt class="text-xs text-muted-foreground">
                    {{ $t('webhooks.show.http_status') }}
                </dt>
                <dd class="mt-0.5 truncate font-medium text-foreground">
                    <template v-if="log.response_status">
                        <span class="font-mono">{{ log.response_status }}</span>
                        <span class="text-muted-foreground">
                            · {{ httpReason(log.response_status) }}
                        </span>
                    </template>
                    <span v-else class="text-muted-foreground">
                        {{ $t('webhooks.show.no_response') }}
                    </span>
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs text-muted-foreground">
                    {{ $t('webhooks.show.attempts') }}
                </dt>
                <dd class="mt-0.5 font-medium text-foreground">
                    {{ log.attempts }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs text-muted-foreground">
                    {{ $t('webhooks.show.delivered_at') }}
                </dt>
                <dd class="mt-0.5 truncate font-medium text-foreground">
                    <template v-if="log.delivered_at">
                        {{ date.formatDateTime(log.delivered_at) }}
                    </template>
                    <span v-else class="text-muted-foreground">
                        {{ $t('webhooks.never') }}
                    </span>
                </dd>
            </div>
        </dl>

        <div class="space-y-5 px-4 py-4 lg:px-6">
            <section class="min-w-0 space-y-2">
                <h3 class="text-xs font-medium text-muted-foreground">
                    {{ $t('webhooks.show.payload') }}
                </h3>
                <JsonViewer :value="log.payload" />
            </section>

            <section class="min-w-0 space-y-2">
                <h3 class="text-xs font-medium text-muted-foreground">
                    {{ $t('webhooks.show.response_body') }}
                </h3>
                <JsonViewer
                    v-if="log.response_body"
                    :value="parsedResponseBody"
                />
                <p
                    v-else
                    class="rounded-md border border-dashed border-border px-3 py-2.5 text-xs text-muted-foreground"
                >
                    {{ $t('webhooks.show.no_response_body') }}
                </p>
            </section>
        </div>
    </div>
</template>
