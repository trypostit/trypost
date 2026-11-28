<script setup lang="ts">
import { deliveryBadgeVariant, deliveryState } from '@/components/app/webhook/webhook-delivery';
import { Badge } from '@/components/ui/badge';
import type { WebhookLog } from '@/types/app/webhook';


defineProps<{
    log: WebhookLog;
}>();
</script>

<template>
    <Badge
        :variant="deliveryBadgeVariant(log)"
        :class="['h-5 px-1.5', { 'font-mono': log.response_status }]"
    >
        <template v-if="log.response_status">{{ log.response_status }}</template>
        <template v-else-if="deliveryState(log) === 'failed'">{{
            $t('webhooks.show.no_response')
        }}</template>
        <template v-else>{{ $t('webhooks.deliveries.pending') }}</template>
    </Badge>
</template>
