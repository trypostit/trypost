<script setup lang="ts">
import {
    IconAlertTriangle,
    IconDots,
    IconExternalLink,
    IconPlayerPause,
    IconPlayerPlay,
    IconPlugConnectedX,
    IconRefresh,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import {
    isConnectionLost,
    type ConnectedAccount,
} from '@/types/social-account';

const props = defineProps<{
    account: ConnectedAccount;
    /** Shown when the account came in through a variant of its network (LinkedIn Page, Instagram via Facebook). */
    variantLabel?: string;
}>();

const emit = defineEmits<{
    reconnect: [account: ConnectedAccount];
    disconnect: [account: ConnectedAccount];
    toggle: [account: ConnectedAccount];
}>();

const lost = computed(() => isConnectionLost(props.account));
const paused = computed(() => props.account.is_active === false);
</script>

<template>
    <div
        :class="[
            'group @container relative flex items-center gap-3 rounded-xl border-2 border-foreground px-3 py-2.5 shadow-xs',
            lost ? 'bg-amber-50' : paused ? 'bg-muted/60' : 'bg-card',
        ]"
        :data-testid="`account-card-${account.id}`"
    >
        <div class="relative shrink-0">
            <Avatar
                :src="account.avatar_url"
                :name="account.display_name || account.username"
                :class="[
                    'size-10 rounded-full border-2 border-foreground shadow-2xs',
                    paused && !lost ? 'grayscale' : '',
                ]"
                fallback-class="bg-secondary text-xs font-black"
            />
            <span
                class="absolute -right-1 -bottom-1 inline-flex size-5 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs"
            >
                <img
                    :src="getPlatformLogo(account.platform)"
                    :alt="account.platform"
                    class="size-full object-cover"
                />
            </span>
        </div>

        <div class="min-w-0 flex-1">
            <p
                class="truncate text-sm leading-tight font-semibold text-foreground"
            >
                {{ account.display_name || account.handle_label }}
            </p>
            <p
                v-if="lost"
                class="mt-0.5 flex items-center gap-1 truncate text-xs font-medium text-amber-700"
            >
                <IconAlertTriangle
                    class="size-3.5 shrink-0"
                    stroke-width="2.5"
                />
                <span class="truncate">{{
                    $t('accounts.connection_lost')
                }}</span>
            </p>
            <p v-else class="mt-0.5 truncate text-xs text-foreground/60">
                {{ account.handle_label
                }}<template v-if="variantLabel"> · {{ variantLabel }}</template>
            </p>
        </div>

        <TooltipProvider v-if="lost" :delay-duration="200">
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        size="icon"
                        variant="outline"
                        class="size-8 shrink-0 bg-amber-200 hover:bg-amber-300"
                        :aria-label="$t('accounts.reconnect')"
                        :data-testid="`reconnect-button-${account.id}`"
                        @click="emit('reconnect', account)"
                    >
                        <IconRefresh class="size-4" stroke-width="2.5" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>{{ $t('accounts.reconnect') }}</TooltipContent>
            </Tooltip>
        </TooltipProvider>
        <Badge v-else-if="paused" variant="outline">
            {{ $t('accounts.paused') }}
        </Badge>
        <Badge v-else variant="success">
            {{ $t('accounts.active') }}
        </Badge>

        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    variant="ghost"
                    size="icon"
                    class="-mr-1.5 size-8 shrink-0"
                    :aria-label="$t('accounts.actions')"
                    :data-testid="`account-menu-${account.id}`"
                >
                    <IconDots class="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" class="w-48">
                <DropdownMenuItem
                    v-if="lost"
                    :data-testid="`reconnect-${account.id}`"
                    @click="emit('reconnect', account)"
                >
                    <IconRefresh class="size-4" />
                    {{ $t('accounts.reconnect') }}
                </DropdownMenuItem>
                <DropdownMenuItem v-if="account.profile_url" as-child>
                    <a
                        :href="account.profile_url"
                        target="_blank"
                        rel="noopener"
                    >
                        <IconExternalLink class="size-4" />
                        {{ $t('accounts.view_profile') }}
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem
                    :data-testid="`toggle-${account.id}`"
                    @click="emit('toggle', account)"
                >
                    <IconPlayerPlay v-if="paused" class="size-4" />
                    <IconPlayerPause v-else class="size-4" />
                    {{
                        paused
                            ? $t('accounts.activate')
                            : $t('accounts.deactivate')
                    }}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    variant="destructive"
                    :data-testid="`disconnect-${account.id}`"
                    @click="emit('disconnect', account)"
                >
                    <IconPlugConnectedX class="size-4" />
                    {{ $t('accounts.disconnect') }}
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
