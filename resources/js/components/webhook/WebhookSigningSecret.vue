<script setup lang="ts">
import { IconCopy, IconEye, IconEyeOff } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { copyToClipboard } from '@/lib/utils';

const props = defineProps<{
    secret: string;
}>();

const secretVisible = ref(false);

const toggleSecretVisibility = (): void => {
    secretVisible.value = !secretVisible.value;
};

const copySecret = (): void => {
    copyToClipboard(props.secret, trans('webhooks.copied.secret'));
};

const displaySecret = computed((): string =>
    secretVisible.value
        ? props.secret
        : `${props.secret.slice(0, 5)}••••••••••••••••••••••••`,
);
</script>

<template>
    <div
        class="flex min-w-0 items-center gap-1 rounded-lg bg-muted p-1 ps-3"
        data-testid="webhook-signing-secret"
    >
        <code
            class="min-w-0 flex-1 truncate font-mono text-sm text-foreground"
            data-testid="signing-secret"
            >{{ displaySecret }}</code
        >
        <TooltipProvider :delay-duration="200">
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="shrink-0 text-muted-foreground"
                        :aria-label="
                            secretVisible
                                ? $t('webhooks.actions.hide_secret')
                                : $t('webhooks.actions.reveal_secret')
                        "
                        data-testid="toggle-secret"
                        @click="toggleSecretVisibility"
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
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="shrink-0 text-muted-foreground"
                        :aria-label="$t('webhooks.actions.copy_secret')"
                        data-testid="copy-secret"
                        @click="copySecret"
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
</template>
