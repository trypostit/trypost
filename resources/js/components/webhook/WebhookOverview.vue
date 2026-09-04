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

    return `${props.webhook.signing_secret.slice(0, 5)}••••••••••••`;
});
</script>

<template>
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
                    {{ webhookEventLabel(event) }}
                </Badge>
            </div>
        </div>
    </div>
</template>
