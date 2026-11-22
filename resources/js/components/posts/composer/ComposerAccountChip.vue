<script setup lang="ts">
import { IconX } from '@tabler/icons-vue';
import { ref } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useCanHover } from '@/composables/useCanHover';
import {
    getPlatformLabel,
} from '@/composables/usePlatformLogo';
import type { ComposerAccount } from '@/types/post-composition';

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
const canHover = useCanHover();

const focusAccount = (): void => {
    tooltipOpen.value = canHover.value;
    emit('focus');
};

const remove = (): void => {
    tooltipOpen.value = false;
    emit('remove');
};
</script>

<template>
    <div class="group relative shrink-0">
        <TooltipProvider :delay-duration="150" :disabled="!canHover">
            <Tooltip v-model:open="tooltipOpen" :disabled="!canHover">
                <TooltipTrigger as-child>
                    <button
                        type="button"
                        :data-testid="`composer-account-${account.id}`"
                        :aria-pressed="active"
                        class="relative block rounded-xl transition-shadow outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
                        @click="focusAccount"
                    >
                        <ChannelAvatar
                            :status="account.status"
                            :account-id="account.id"
                            :platform="account.platform"
                            :src="account.avatar_url"
                            :verified="account.verified_badge"
                            :name="account.display_name || account.username"
                            :size="40"
                            :reserve-space="false"
                            class="align-top"
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
            :aria-label="$t('posts.composer.remove_account', { name: account.handle_label })"
            class="absolute -top-2.5 -right-3 z-10 flex size-6 items-center justify-center rounded-md border border-border-strong bg-background text-muted-foreground opacity-100 transition-[opacity,background-color] hover:bg-accent sm:opacity-0 sm:group-focus-within:opacity-100 sm:group-hover:opacity-100"
            @click.stop="remove"
        >
            <IconX class="size-4" />
        </button>
    </div>
</template>
