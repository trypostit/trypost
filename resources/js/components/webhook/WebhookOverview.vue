<script setup lang="ts">
import { IconCopy, IconEye, IconEyeOff } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { copyToClipboard } from '@/lib/utils';
import type { WebhookWithSecret } from '@/types/webhook';

import { webhookEventLabel } from './webhook-events';

const props = defineProps<{
    webhook: WebhookWithSecret;
}>();

const secretVisible = ref(false);

const displaySecret = computed(() => {
    if (secretVisible.value) {
        return props.webhook.signing_secret;
    }

    return `${props.webhook.signing_secret.slice(0, 5)}••••••••••••••••`;
});
</script>

<template>
    <dl
        class="grid shrink-0 gap-x-8 gap-y-4 border-b border-border px-4 py-3 text-sm lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)] lg:px-6"
        data-testid="webhook-overview"
    >
        <div class="min-w-0">
            <dt class="mb-1.5 text-xs font-medium text-muted-foreground">
                {{ $t('webhooks.show.signing_secret') }}
            </dt>
            <dd class="flex min-w-0 items-center gap-1.5">
                <code
                    class="flex h-8 min-w-0 flex-1 items-center overflow-hidden rounded-md border border-input bg-muted px-2.5 font-mono text-xs text-foreground"
                    data-testid="signing-secret"
                >
                    <span class="block min-w-0 truncate">{{
                        displaySecret
                    }}</span>
                </code>
                <TooltipProvider :delay-duration="200">
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                variant="outline"
                                size="icon-sm"
                                data-testid="toggle-secret"
                                @click="secretVisible = !secretVisible"
                            >
                                <IconEyeOff
                                    v-if="secretVisible"
                                    class="size-4"
                                />
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
                    <Tooltip>
                        <TooltipTrigger as-child>
                            <Button
                                variant="outline"
                                size="icon-sm"
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
            </dd>
        </div>

        <div class="min-w-0">
            <dt class="mb-1.5 text-xs font-medium text-muted-foreground">
                {{ $t('webhooks.show.listening_for') }}
                <span class="text-muted-foreground/70"
                    >({{ webhook.events.length }})</span
                >
            </dt>
            <dd class="flex flex-wrap gap-1">
                <Badge
                    v-for="event in webhook.events"
                    :key="event"
                    variant="secondary"
                    class="font-normal"
                >
                    {{ webhookEventLabel(event) }}
                </Badge>
            </dd>
        </div>
    </dl>
</template>
