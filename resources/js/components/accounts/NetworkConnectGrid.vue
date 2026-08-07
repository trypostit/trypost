<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { IconAlertTriangle, IconCheck } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import TelegramConnectDialog from '@/components/accounts/TelegramConnectDialog.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { Button } from '@/components/ui/button';
import { useOAuthPopup } from '@/composables/useOAuthPopup';
import { disconnect } from '@/routes/app/accounts';
import { Platform } from '@/types/platform';

export interface AvailablePlatform {
    value: string;
    label: string;
    color: string;
    network: string;
}

export interface ConnectedAccount {
    id: string;
    platform: string;
    network: string;
    username: string;
    display_name: string;
    avatar_url: string | null;
    status: 'connected' | 'disconnected' | 'token_expired' | null;
}

const props = withDefaults(
    defineProps<{
        platforms: AvailablePlatform[];
        connectedAccounts?: ConnectedAccount[];
        gridClass?: string;
    }>(),
    {
        connectedAccounts: () => [],
        gridClass: 'grid-cols-2 sm:grid-cols-3 lg:grid-cols-5',
    },
);

const getPlatformDescription = (platform: string): string =>
    trans(`accounts.descriptions.${platform}`);

// Mirrors `NetworksGrid.vue` from the marketing site — pastel tile bg
// + ink 2px border + slight rotation per platform, real PNG logo inside.
// `instagram-facebook` falls back to the base brand image and same color
// since it's a variant of the same network.
const platformTheme: Record<
    string,
    { bg: string; rotate: string; image: string }
> = {
    instagram: {
        bg: 'bg-pink-200',
        rotate: '-rotate-2',
        image: '/images/accounts/instagram.png',
    },
    'instagram-facebook': {
        bg: 'bg-pink-200',
        rotate: '-rotate-2',
        image: '/images/accounts/instagram.png',
    },
    facebook: {
        bg: 'bg-sky-200',
        rotate: 'rotate-1',
        image: '/images/accounts/facebook.png',
    },
    linkedin: {
        bg: 'bg-blue-200',
        rotate: '-rotate-1',
        image: '/images/accounts/linkedin.png',
    },
    x: {
        bg: 'bg-amber-200',
        rotate: 'rotate-2',
        image: '/images/accounts/x.png',
    },
    tiktok: {
        bg: 'bg-fuchsia-200',
        rotate: '-rotate-1',
        image: '/images/accounts/tiktok.png',
    },
    youtube: {
        bg: 'bg-red-200',
        rotate: 'rotate-1',
        image: '/images/accounts/youtube.png',
    },
    pinterest: {
        bg: 'bg-rose-200',
        rotate: '-rotate-2',
        image: '/images/accounts/pinterest.png',
    },
    threads: {
        bg: 'bg-emerald-200',
        rotate: 'rotate-2',
        image: '/images/accounts/threads.png',
    },
    bluesky: {
        bg: 'bg-cyan-200',
        rotate: '-rotate-1',
        image: '/images/accounts/bluesky.png',
    },
    mastodon: {
        bg: 'bg-violet-200',
        rotate: 'rotate-1',
        image: '/images/accounts/mastodon.png',
    },
    telegram: {
        bg: 'bg-sky-200',
        rotate: '-rotate-2',
        image: '/images/accounts/telegram.png',
    },
    discord: {
        bg: 'bg-indigo-200',
        rotate: 'rotate-1',
        image: '/images/accounts/discord.png',
    },
};

const themeFor = (value: string) =>
    platformTheme[value] ?? { bg: 'bg-muted', rotate: '', image: '' };

// One account per network: map each connected network to its account so every
// platform card belonging to that network reflects the connection.
const connectedByNetwork = computed((): Record<string, ConnectedAccount> => {
    const map: Record<string, ConnectedAccount> = {};

    for (const account of props.connectedAccounts) {
        if (!map[account.network]) {
            map[account.network] = account;
        }
    }

    return map;
});

// platform value -> the account occupying its network (if any).
const cardConnection = computed(
    (): Record<string, ConnectedAccount | undefined> => {
        const map: Record<string, ConnectedAccount | undefined> = {};

        for (const platform of props.platforms) {
            map[platform.value] = connectedByNetwork.value[platform.network];
        }

        return map;
    },
);

const telegramOpen = ref(false);
const disconnectModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(
    null,
);

const { openOAuthPopup } = useOAuthPopup((result) => {
    if (result.success) {
        toast.success(result.message);
        router.reload();
        return;
    }

    toast.error(result.message);
});

const disconnectAccount = (account: ConnectedAccount) => {
    disconnectModal.value?.open({
        url: disconnect.url(account.id),
        confirmText: account.username || account.display_name,
    });
};

const needsReconnect = (account: ConnectedAccount): boolean =>
    account.status === 'disconnected' || account.status === 'token_expired';

const connectEntryFor = (platformValue: string): string =>
    platformValue === Platform.LinkedInPage ? Platform.LinkedIn : platformValue;

const openConnect = (platformValue: string) => {
    if (platformValue === Platform.Telegram) {
        telegramOpen.value = true;
        return;
    }

    openOAuthPopup(platformValue);
};

const connectPlatform = (platformValue: string) => {
    if (cardConnection.value[platformValue]) {
        return;
    }

    openConnect(platformValue);
};

const reconnectAccount = (account: ConnectedAccount) => {
    openConnect(connectEntryFor(account.platform));
};

const CardState = {
    Connect: 'connect',
    Connected: 'connected',
    Reconnect: 'reconnect',
} as const;

type CardStateValue = (typeof CardState)[keyof typeof CardState];

const cardState = computed((): Record<string, CardStateValue> => {
    const map: Record<string, CardStateValue> = {};

    for (const platform of props.platforms) {
        const account = connectedByNetwork.value[platform.network];
        map[platform.value] = !account
            ? CardState.Connect
            : needsReconnect(account)
              ? CardState.Reconnect
              : CardState.Connected;
    }

    return map;
});
</script>

<template>
    <div>
        <div :class="['grid gap-4', gridClass]">
            <div
                v-for="platform in platforms"
                :key="platform.value"
                :class="[
                    'group relative flex flex-col items-center gap-3 rounded-xl border-2 border-foreground p-4 text-center shadow-xs transition-shadow',
                    cardState[platform.value] === CardState.Connected
                        ? 'bg-emerald-50'
                        : cardState[platform.value] === CardState.Reconnect
                          ? 'bg-amber-50'
                          : 'bg-card hover:shadow-md',
                ]"
            >
                <span
                    v-if="cardState[platform.value] === CardState.Connected"
                    class="absolute -top-2 -right-2 inline-flex size-6 items-center justify-center rounded-full border-2 border-foreground bg-emerald-200 text-emerald-700 shadow-2xs"
                    aria-hidden="true"
                >
                    <IconCheck class="size-3.5" stroke-width="3" />
                </span>
                <span
                    v-else-if="cardState[platform.value] === CardState.Reconnect"
                    class="absolute -top-2 -right-2 inline-flex size-6 items-center justify-center rounded-full border-2 border-foreground bg-amber-200 text-amber-700 shadow-2xs"
                    aria-hidden="true"
                >
                    <IconAlertTriangle class="size-3.5" stroke-width="2.5" />
                </span>

                <div
                    :class="[
                        themeFor(platform.value).bg,
                        themeFor(platform.value).rotate,
                        'inline-flex size-16 items-center justify-center rounded-2xl border-2 border-foreground shadow-sm transition-transform group-hover:!rotate-0',
                    ]"
                >
                    <img
                        :src="themeFor(platform.value).image"
                        :alt="platform.label"
                        class="size-9 rounded-lg"
                        loading="lazy"
                    />
                </div>

                <div class="w-full min-w-0 flex-1">
                    <span
                        class="block truncate text-sm font-semibold text-foreground"
                    >
                        <template v-if="platform.label.includes('(')">
                            {{ platform.label.split('(')[0].trim() }}
                        </template>
                        <template v-else>{{ platform.label }}</template>
                    </span>
                    <p
                        v-if="cardState[platform.value] === CardState.Connect"
                        class="mt-0.5 line-clamp-2 text-xs leading-tight text-foreground/60"
                    >
                        {{ getPlatformDescription(platform.value) }}
                    </p>
                    <p
                        v-else-if="cardState[platform.value] === CardState.Reconnect"
                        class="mt-0.5 truncate text-xs leading-tight font-medium text-amber-700"
                    >
                        {{ $t('accounts.connection_lost') }}
                    </p>
                    <p
                        v-else
                        class="mt-0.5 truncate text-xs leading-tight text-foreground/70"
                    >
                        {{
                            cardConnection[platform.value]?.display_name ||
                            cardConnection[platform.value]?.username
                        }}
                    </p>
                </div>

                <Button
                    v-if="cardState[platform.value] === CardState.Reconnect"
                    size="sm"
                    class="mt-auto w-full"
                    @click="reconnectAccount(cardConnection[platform.value]!)"
                >
                    {{ $t('accounts.reconnect') }}
                </Button>
                <Button
                    v-else-if="cardState[platform.value] === CardState.Connected"
                    variant="destructive"
                    size="sm"
                    class="mt-auto w-full"
                    @click="disconnectAccount(cardConnection[platform.value]!)"
                >
                    {{ $t('accounts.disconnect') }}
                </Button>
                <Button
                    v-else
                    size="sm"
                    class="mt-auto w-full"
                    @click="connectPlatform(platform.value)"
                >
                    {{ $t('accounts.connect_cta') }}
                </Button>
            </div>
        </div>

        <TelegramConnectDialog v-model:open="telegramOpen" />

        <ConfirmDeleteModal
            ref="disconnectModal"
            :title="$t('accounts.disconnect_modal.title')"
            :description="$t('accounts.disconnect_modal.description')"
            :action="$t('accounts.disconnect_modal.confirm')"
            :cancel="$t('accounts.disconnect_modal.cancel')"
        />
    </div>
</template>
