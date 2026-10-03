<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    IconAlertCircle,
    IconArrowDown,
    IconArrowUp,
    IconDotsVertical,
    IconExternalLink,
    IconRefresh,
    IconSettings,
    IconTrash,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { settings as settingsRoute } from '@/routes/app/channels';
import {
    accountTypeKey,
    isConnectionLost,
    type ConnectedAccount,
} from '@/types/social-account';

const props = withDefaults(
    defineProps<{
        channel: ConnectedAccount;
        showSettings?: boolean;
        canMoveUp?: boolean;
        canMoveDown?: boolean;
    }>(),
    { showSettings: true, canMoveUp: false, canMoveDown: false },
);

const emit = defineEmits<{
    reconnect: [channel: ConnectedAccount];
    disconnect: [channel: ConnectedAccount];
    move: [channel: ConnectedAccount, offset: -1 | 1];
}>();

const lost = computed(() => isConnectionLost(props.channel));

const typeKey = computed(() => accountTypeKey(props.channel));
</script>

<template>
    <div
        class="flex items-center gap-3 rounded-xl border border-border bg-card p-4"
        :data-testid="`channel-row-${channel.id}`"
    >
        <slot name="handle" />

        <ChannelAvatar
            :status="channel.status"
            :account-id="channel.id"
            :platform="channel.platform"
            :src="channel.avatar_url"
            :name="channel.display_name || channel.username"
            :size="40"
            ring="card"
        />

        <div class="min-w-0 flex-1">
            <p
                class="truncate text-sm leading-tight font-emphasis text-foreground"
                :data-testid="`channel-name-${channel.id}`"
            >
                {{ channel.display_name || channel.username }}
            </p>
            <p
                v-if="lost"
                class="flex min-w-0 items-center gap-1.5 text-sm text-destructive"
                :data-testid="`channel-connection-lost-${channel.id}`"
            >
                <IconAlertCircle class="size-4 shrink-0" />
                <span class="truncate">{{ $t('channels.connection_lost_hint') }}</span>
            </p>
            <p v-else class="truncate text-sm text-muted-foreground">
                {{
                    typeKey
                        ? $t(typeKey)
                        : getPlatformLabel(channel.platform)
                }}
            </p>
        </div>

        <Button
            v-if="lost"
            size="sm"
            class="shrink-0"
            :data-testid="`channel-reconnect-${channel.id}`"
            @click="emit('reconnect', channel)"
        >
            <IconRefresh class="size-4" />
            {{ $t('channels.reconnect') }}
        </Button>

        <div class="flex shrink-0 items-center">
            <Button
                v-if="showSettings"
                as-child
                variant="ghost"
                size="icon"
            >
                <Link
                    :href="settingsRoute.url(channel.id)"
                    :aria-label="$t('channels.settings')"
                    :data-testid="`channel-settings-${channel.id}`"
                >
                    <IconSettings class="size-4" />
                </Link>
            </Button>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button
                        variant="ghost"
                        size="icon"
                        class="data-[state=open]:bg-accent"
                        :aria-label="$t('channels.actions')"
                        :data-testid="`channel-menu-${channel.id}`"
                    >
                        <IconDotsVertical class="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem v-if="channel.profile_url" as-child>
                        <a
                            :href="channel.profile_url"
                            target="_blank"
                            rel="noopener"
                            :data-testid="`channel-menu-profile-${channel.id}`"
                        >
                            <IconExternalLink class="size-4" />
                            {{ $t('channels.view_profile') }}
                        </a>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        :data-testid="`channel-menu-reconnect-${channel.id}`"
                        @click="emit('reconnect', channel)"
                    >
                        <IconRefresh class="size-4" />
                        {{ $t('channels.refresh_connection') }}
                    </DropdownMenuItem>
                    <template v-if="canMoveUp || canMoveDown">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            :disabled="!canMoveUp"
                            :data-testid="`channel-menu-move-up-${channel.id}`"
                            @click="emit('move', channel, -1)"
                        >
                            <IconArrowUp class="size-4" />
                            {{ $t('channels.reorder.move_up') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :disabled="!canMoveDown"
                            :data-testid="`channel-menu-move-down-${channel.id}`"
                            @click="emit('move', channel, 1)"
                        >
                            <IconArrowDown class="size-4" />
                            {{ $t('channels.reorder.move_down') }}
                        </DropdownMenuItem>
                    </template>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        :data-testid="`channel-disconnect-${channel.id}`"
                        @click="emit('disconnect', channel)"
                    >
                        <IconTrash class="size-4" />
                        {{ $t('channels.disconnect') }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </div>
</template>
