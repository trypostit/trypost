<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconRefresh } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import JsonViewer from '@/components/JsonViewer.vue';
import { Badge } from '@/components/ui/badge';
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
    <div class="overflow-y-auto lg:col-span-2">
        <div class="space-y-6 p-5 sm:p-6">
            <div class="flex items-center justify-between gap-3">
                <Badge variant="default">
                    {{ webhookEventLabel(log.event_type) }}
                </Badge>
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

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="rounded-xl border-2 border-foreground bg-background p-3 shadow-2xs">
                    <p class="text-sm font-bold text-foreground">
                        {{ $t('webhooks.show.http_status') }}
                    </p>
                    <p class="mt-1 text-sm font-medium text-foreground/80">
                        {{ formatStatusCode(log.response_status) }}
                    </p>
                </div>
                <div class="rounded-xl border-2 border-foreground bg-background p-3 shadow-2xs">
                    <p class="text-sm font-bold text-foreground">
                        {{ $t('webhooks.show.attempts') }}
                    </p>
                    <p class="mt-1 text-sm font-medium text-foreground/80">
                        {{ log.attempts }}
                    </p>
                </div>
                <div class="rounded-xl border-2 border-foreground bg-background p-3 shadow-2xs">
                    <p class="text-sm font-bold text-foreground">
                        {{ $t('webhooks.show.delivered_at') }}
                    </p>
                    <p class="mt-1 text-sm font-medium text-foreground/80">
                        {{
                            log.delivered_at
                                ? date.formatDateTime(log.delivered_at)
                                : $t('webhooks.never')
                        }}
                    </p>
                </div>
            </div>

            <div class="space-y-2">
                <p class="text-sm font-bold text-foreground">
                    {{ $t('webhooks.show.response_body') }}
                </p>
                <div v-if="log.response_body">
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
                <JsonViewer :value="log.payload" />
            </div>
        </div>
    </div>
</template>
