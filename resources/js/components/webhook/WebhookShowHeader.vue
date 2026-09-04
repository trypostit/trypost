<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { IconArrowLeft } from '@tabler/icons-vue';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import WebhookActionsMenu from '@/components/webhook/WebhookActionsMenu.vue';
import date from '@/date';
import { index } from '@/routes/app/webhooks';
import type { Webhook } from '@/types/webhook';
import { webhookStatusVariant } from '@/types/webhook-status';

defineProps<{
    webhook: Webhook;
}>();

defineEmits<{
    edit: [];
    rotate: [];
    delete: [];
}>();
</script>

<template>
    <header
        class="flex shrink-0 flex-col gap-3 border-b-2 border-foreground bg-card px-4 py-3 md:flex-row md:items-center md:justify-between md:px-6"
    >
        <div class="flex min-w-0 items-center gap-3 pl-12 md:pl-0">
            <Link :href="index.url()">
                <Button variant="outline" size="icon-sm" :aria-label="$t('common.back')">
                    <IconArrowLeft class="size-4" />
                </Button>
            </Link>
            <div class="min-w-0 space-y-0.5">
                <div class="flex min-w-0 items-center gap-2">
                    <h1 class="truncate text-lg font-semibold text-foreground">
                        {{ $t('webhooks.title') }}
                    </h1>
                    <Badge :variant="webhookStatusVariant(webhook.status)" class="shrink-0">
                        {{ $t(`webhooks.status.${webhook.status}`) }}
                    </Badge>
                </div>
                <p class="truncate font-mono text-xs text-foreground/60">
                    {{ webhook.endpoint }}
                </p>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 md:justify-end">
            <span
                v-if="webhook.last_sent_at"
                class="truncate text-sm font-medium text-foreground/60"
            >
                {{
                    $t('webhooks.show.last_sent', {
                        time: date.diffForHumans(webhook.last_sent_at),
                    })
                }}
            </span>
            <WebhookActionsMenu
                :webhook="webhook"
                @edit="$emit('edit')"
                @rotate="$emit('rotate')"
                @delete="$emit('delete')"
            />
        </div>
    </header>
</template>
