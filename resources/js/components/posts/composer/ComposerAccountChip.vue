<script setup lang="ts">
import { IconX } from '@tabler/icons-vue';
import { ref } from 'vue';

import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    getPlatformLabel,
    getPlatformLogo,
} from '@/composables/usePlatformLogo';
import type { ComposerAccount } from '@/composables/usePostComposition';

defineProps<{
    account: ComposerAccount;
    active: boolean;
    removable: boolean;
}>();

const emit = defineEmits<{
    (event: 'focus'): void;
    (event: 'remove'): void;
}>();

const tooltipOpen = ref(false);

const remove = (): void => {
    tooltipOpen.value = false;
    emit('remove');
};
</script>

<template>
    <div class="group relative shrink-0">
        <TooltipProvider :delay-duration="150">
            <Tooltip v-model:open="tooltipOpen">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        :data-testid="`composer-account-${account.id}`"
                        :aria-pressed="active"
                        class="relative block size-10 rounded-lg transition-shadow outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        :class="active ? 'ring-1 ring-primary/50' : ''"
                        @click="
                            tooltipOpen = true;
                            emit('focus');
                        "
                    >
                        <img
                            :src="
                                account.avatar_url ||
                                getPlatformLogo(account.platform)
                            "
                            :alt="account.display_name || account.username"
                            class="size-full rounded-lg object-cover"
                        />
                        <img
                            :src="getPlatformLogo(account.platform)"
                            :alt="getPlatformLabel(account.platform)"
                            class="absolute -right-1 -bottom-1 size-4 rounded-full bg-background"
                        />
                    </button>
                </TooltipTrigger>
                <TooltipContent side="top">
                    {{ account.handle_label }} ·
                    {{ getPlatformLabel(account.platform) }}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
        <button
            v-if="removable"
            type="button"
            :data-testid="`composer-remove-account-${account.id}`"
            :aria-label="`${$t('settings.remove')} ${account.handle_label}`"
            class="absolute -top-2 -right-2 z-10 flex size-5 items-center justify-center rounded-full border border-border bg-background opacity-100 shadow-sm transition-opacity sm:opacity-0 sm:group-focus-within:opacity-100 sm:group-hover:opacity-100"
            @click.stop="remove"
        >
            <IconX class="size-3" />
        </button>
    </div>
</template>
