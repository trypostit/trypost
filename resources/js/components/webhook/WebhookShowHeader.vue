<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconArrowLeft, IconWebhook } from '@tabler/icons-vue';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import WebhookActionsMenu from '@/components/webhook/WebhookActionsMenu.vue';
import date from '@/date';
import { index } from '@/routes/app/webhooks';
import type { Webhook } from '@/types/webhook';
import { webhookStatusVariant } from '@/types/webhook-status';

defineProps<{
    webhook: Webhook;
    host: string;
}>();

defineEmits<{
    edit: [];
    rotate: [];
    delete: [];
}>();
</script>

<template>
    <div class="space-y-6">
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
                        {{ host }}
                    </h1>
                    <p class="break-all font-mono text-sm text-foreground/70">
                        {{ webhook.endpoint }}
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge :variant="webhookStatusVariant(webhook.status)">
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

            <WebhookActionsMenu
                :webhook="webhook"
                @edit="$emit('edit')"
                @rotate="$emit('rotate')"
                @delete="$emit('delete')"
            />
        </div>
    </div>
</template>
