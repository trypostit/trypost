<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { IconAlertTriangle, IconCheck } from '@tabler/icons-vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';

import InstagramConnectDialog from '@/components/accounts/InstagramConnectDialog.vue';
import TelegramConnectDialog from '@/components/accounts/TelegramConnectDialog.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import { Button } from '@/components/ui/button';
import { oauthConnectUrl, useOAuthPopup } from '@/composables/useOAuthPopup';
import { getPlatformTheme } from '@/composables/usePlatformLogo';
import { disconnect } from '@/routes/app/accounts';
import { Platform } from '@/types/platform';
import {
    SocialAccountStatus,
    type SocialAccountStatusValue,
} from '@/types/social-account-status';

export interface AvailablePlatform {
    value: string;
    label: string;
    network: string;
    connect_methods?: string[];
}

export interface ConnectedAccount {
    id: string;
    platform: string;
    network: string;
    username: string;
    display_name: string;
    display_label: string;
    handle_label: string;
    avatar_url: string | null;
    status: SocialAccountStatusValue | null;
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

const telegramOpen = ref(false);
const telegramReconnectId = ref<string>();
const instagramOpen = ref(false);
const disconnectModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const { openOAuthPopup } = useOAuthPopup((result) => {
    if (result.success) {
        toast.success(result.message);
        router.reload();
        return;
    }

    toast.error(result.message);
});

const connectEntry = (platform: string): string =>
    platform === Platform.LinkedInPage ? Platform.LinkedIn : platform;

const openConnect = (platform: string, reconnectId?: string) => {
    const url = oauthConnectUrl(platform, reconnectId);

    if (url) {
        openOAuthPopup(url);
    }
};

const startConnect = (platform: string, reconnectId?: string) => {
    const entry = connectEntry(platform);

    if (entry === Platform.Telegram) {
        telegramReconnectId.value = reconnectId;
        telegramOpen.value = true;
        return;
    }

    if (entry === Platform.Instagram && !reconnectId) {
        instagramOpen.value = true;
        return;
    }

    openConnect(entry, reconnectId);
};

const disconnectAccount = (account: ConnectedAccount) => {
    disconnectModal.value?.open({
        url: disconnect.url(account.id),
        confirmText: account.handle_label,
    });
};

const instagramMethods = computed(
    () =>
        props.platforms.find((platform) => platform.value === Platform.Instagram)?.connect_methods ?? [
            Platform.Instagram,
            Platform.InstagramFacebook,
        ],
);

interface ConnectCard {
    key: string;
    platform: AvailablePlatform;
    account?: ConnectedAccount;
    theme: ReturnType<typeof getPlatformTheme>;
    title: string;
    state: 'connected' | 'reconnect' | 'connect';
    extra: boolean;
}

const page = usePage();

const cards = computed<ConnectCard[]>(() => {
    const allowMultiple = Boolean(page.props.allowMultipleSocialAccounts);

    return props.platforms.flatMap((platform) => {
        const accounts = props.connectedAccounts.filter((account) => account.network === platform.network);
        const theme = getPlatformTheme(platform.value);
        const title = platform.label.split('(')[0].trim();

        const connected: ConnectCard[] = accounts.map((account) => {
            const lost =
                account.status === SocialAccountStatus.Disconnected ||
                account.status === SocialAccountStatus.TokenExpired;

            return {
                key: account.id,
                platform,
                account,
                theme,
                title,
                state: lost ? 'reconnect' : 'connected',
                extra: false,
            };
        });

        if (accounts.length === 0 || allowMultiple) {
            connected.push({
                key: `${platform.value}-connect`,
                platform,
                theme,
                title,
                state: 'connect',
                extra: accounts.length > 0,
            });
        }

        return connected;
    });
});
</script>

<template>
    <div>
        <div :class="['grid gap-4', gridClass]">
            <div
                v-for="card in cards"
                :key="card.key"
                :class="[
                    'group relative flex flex-col items-center gap-3 rounded-xl border-2 border-foreground p-4 text-center shadow-xs transition-shadow',
                    card.state === 'connected'
                        ? 'bg-emerald-50'
                        : card.state === 'reconnect'
                          ? 'bg-amber-50'
                          : 'bg-card hover:shadow-md',
                ]"
            >
                <span
                    v-if="card.state !== 'connect'"
                    :class="[
                        'absolute -top-2 -right-2 inline-flex size-6 items-center justify-center rounded-full border-2 border-foreground shadow-2xs',
                        card.state === 'connected'
                            ? 'bg-emerald-200 text-emerald-700'
                            : 'bg-amber-200 text-amber-700',
                    ]"
                    aria-hidden="true"
                >
                    <IconCheck
                        v-if="card.state === 'connected'"
                        class="size-3.5"
                        stroke-width="3"
                    />
                    <IconAlertTriangle
                        v-else
                        class="size-3.5"
                        stroke-width="2.5"
                    />
                </span>

                <div
                    :class="[
                        card.theme.bg,
                        card.theme.rotate,
                        'inline-flex size-16 items-center justify-center rounded-2xl border-2 border-foreground shadow-sm transition-transform group-hover:!rotate-0',
                    ]"
                >
                    <img
                        :src="card.theme.image"
                        :alt="card.platform.label"
                        class="size-9 rounded-lg"
                        loading="lazy"
                    />
                </div>

                <div class="w-full min-w-0 flex-1">
                    <span class="block truncate text-sm font-semibold text-foreground">
                        {{ card.title }}
                    </span>
                    <p
                        v-if="card.state === 'connect'"
                        class="mt-0.5 line-clamp-2 text-xs leading-tight text-foreground/60"
                    >
                        {{ $t(`accounts.descriptions.${card.platform.value}`) }}
                    </p>
                    <p
                        v-else-if="card.state === 'reconnect'"
                        class="mt-0.5 truncate text-xs leading-tight font-medium text-amber-700"
                    >
                        {{ $t('accounts.connection_lost') }}
                    </p>
                    <p
                        v-else
                        class="mt-0.5 truncate text-xs leading-tight text-foreground/70"
                    >
                        {{ card.account?.display_label }}
                    </p>
                </div>

                <Button
                    v-if="card.state === 'reconnect' && card.account"
                    size="sm"
                    class="mt-auto w-full"
                    @click="startConnect(card.account.platform, card.account.id)"
                >
                    {{ $t('accounts.reconnect') }}
                </Button>
                <Button
                    v-else-if="card.state === 'connected' && card.account"
                    variant="destructive"
                    size="sm"
                    class="mt-auto w-full"
                    @click="disconnectAccount(card.account)"
                >
                    {{ $t('accounts.disconnect') }}
                </Button>
                <Button
                    v-else
                    size="sm"
                    class="mt-auto w-full"
                    :data-testid="`connect-${card.platform.value}`"
                    @click="startConnect(card.platform.value)"
                >
                    {{
                        card.extra
                            ? $t('accounts.connect_another')
                            : $t('accounts.connect_cta')
                    }}
                </Button>
            </div>
        </div>

        <TelegramConnectDialog
            v-model:open="telegramOpen"
            :reconnect-id="telegramReconnectId"
        />

        <InstagramConnectDialog
            v-model:open="instagramOpen"
            :methods="instagramMethods"
            @select="openConnect"
        />

        <ConfirmDeleteModal
            ref="disconnectModal"
            :title="$t('accounts.disconnect_modal.title')"
            :description="$t('accounts.disconnect_modal.description')"
            :action="$t('accounts.disconnect_modal.confirm')"
            :cancel="$t('accounts.disconnect_modal.cancel')"
        />
    </div>
</template>
