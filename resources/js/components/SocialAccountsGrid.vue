<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import {
    IconAlertCircle,
    IconCheck,
    IconExternalLink,
    IconRefresh,
    IconTrash,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getInitials } from '@/composables/useInitials';
import { useOAuthPopup } from '@/composables/useOAuthPopup';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import { toggle as toggleAccount } from '@/routes/app/accounts';
import {
    SocialAccountStatus,
    type SocialAccountStatusValue,
} from '@/types/social-account-status';

export interface SocialAccount {
    id: string;
    platform: string;
    platform_user_id: string;
    username: string;
    display_name: string;
    display_label: string;
    handle_label: string;
    avatar_url: string;
    status: SocialAccountStatusValue | null;
    is_active: boolean;
    error_message: string | null;
}

export interface Platform {
    value: string;
    label: string;
    color: string;
    connected: boolean;
    account: SocialAccount | null;
}

interface Props {
    platforms: Platform[];
    showDisconnect?: boolean;
    showReconnect?: boolean;
    showViewProfile?: boolean;
    columns?: 2 | 3 | 4;
}

const props = withDefaults(defineProps<Props>(), {
    showDisconnect: true,
    showReconnect: true,
    showViewProfile: true,
    columns: 4,
});

const handleToggle = (accountId: string) => {
    router.put(
        toggleAccount.url(accountId),
        {},
        {
            preserveScroll: true,
        },
    );
};

const { openOAuthPopup } = useOAuthPopup(() => router.reload());

const gridClass = computed(() => {
    switch (props.columns) {
        case 2:
            return 'sm:grid-cols-2';
        case 3:
            return 'sm:grid-cols-2 lg:grid-cols-3';
        case 4:
        default:
            return 'sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4';
    }
});

const emit = defineEmits<{
    disconnect: [accountId: string];
}>();

const getProfileUrl = (
    platform: string,
    username: string | null,
    platformUserId: string | null = null,
): string | null => {
    if (platform === 'facebook') {
        const identifier = username || platformUserId;
        return identifier ? `https://facebook.com/${identifier}` : null;
    }

    if (!username) return null;

    const urls: Record<string, string> = {
        linkedin: `https://linkedin.com/in/${username}`,
        'linkedin-page': `https://linkedin.com/company/${username}`,
        x: `https://x.com/${username}`,
        tiktok: `https://tiktok.com/@${username}`,
        instagram: `https://instagram.com/${username}`,
        'instagram-facebook': `https://instagram.com/${username}`,
        youtube: `https://youtube.com/@${username}`,
        threads: `https://threads.net/@${username}`,
        bluesky: `https://bsky.app/profile/${username}`,
        pinterest: `https://pinterest.com/${username}`,
        telegram: `https://t.me/${username}`,
    };
    return urls[platform] || null;
};

const isDisconnected = (account: SocialAccount | null): boolean => {
    if (!account) return false;
    return (
        account.status === SocialAccountStatus.Disconnected ||
        account.status === SocialAccountStatus.TokenExpired
    );
};
</script>

<template>
    <div class="grid gap-4" :class="gridClass">
        <div
            v-for="platform in platforms"
            :key="platform.value"
            class="group relative overflow-hidden rounded-xl border bg-card transition-all hover:shadow-md"
            :class="{
                'border-green-500/30 bg-green-50/50 dark:bg-green-950/20':
                    platform.connected &&
                    !isDisconnected(platform.account) &&
                    platform.account?.is_active,
                'border-red-500/30 bg-red-50/50 dark:bg-red-950/20':
                    platform.connected &&
                    (isDisconnected(platform.account) ||
                        !platform.account?.is_active),
            }"
        >
            <!-- Platform Header -->
            <div class="flex items-center gap-3 p-4">
                <div class="relative">
                    <img
                        :src="getPlatformLogo(platform.value)"
                        :alt="platform.label"
                        class="h-10 w-10 rounded-full object-contain"
                        :class="{
                            'opacity-40':
                                platform.connected &&
                                platform.account &&
                                !platform.account.is_active,
                        }"
                    />
                    <div
                        v-if="
                            platform.connected &&
                            !isDisconnected(platform.account)
                        "
                        class="absolute -right-0.5 -bottom-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-green-500 text-white ring-2 ring-white dark:ring-neutral-900"
                    >
                        <IconCheck class="h-2 w-2" />
                    </div>
                    <div
                        v-else-if="
                            platform.connected &&
                            isDisconnected(platform.account)
                        "
                        class="absolute -right-0.5 -bottom-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-white ring-2 ring-white dark:ring-neutral-900"
                    >
                        <IconAlertCircle class="h-2 w-2" />
                    </div>
                </div>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center justify-between gap-2">
                        <div class="flex min-w-0 items-center gap-1">
                            <h3 class="leading-tight font-semibold">
                                <template v-if="platform.label.includes('(')">
                                    {{ platform.label.split('(')[0].trim()
                                    }}<br />
                                    <span
                                        class="text-xs font-normal text-muted-foreground"
                                        >({{
                                            platform.label.split('(')[1]
                                        }}</span
                                    >
                                </template>
                                <template v-else>{{ platform.label }}</template>
                            </h3>
                        </div>
                        <Switch
                            v-if="platform.connected && platform.account"
                            :model-value="platform.account.is_active"
                            @update:model-value="
                                handleToggle(platform.account.id)
                            "
                        />
                    </div>
                    <p
                        v-if="platform.connected && platform.account"
                        class="truncate text-sm text-muted-foreground"
                    >
                        @{{ platform.account.handle_label }}
                    </p>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ trans('accounts.not_connected') }}
                    </p>
                </div>
            </div>

            <!-- Connected State -->
            <div
                v-if="platform.connected && platform.account"
                class="border-t px-4 py-3"
            >
                <!-- Disconnected Warning -->
                <div
                    v-if="isDisconnected(platform.account)"
                    class="mb-3 flex items-start gap-2 rounded-lg bg-red-100 p-2 text-sm text-red-700 dark:bg-red-900/30 dark:text-red-400"
                >
                    <IconAlertCircle class="mt-0.5 h-4 w-4 shrink-0" />
                    <div class="min-w-0 flex-1">
                        <p class="font-medium">
                            {{ trans('accounts.connection_lost') }}
                        </p>
                        <p
                            v-if="platform.account.error_message"
                            class="truncate text-xs opacity-80"
                        >
                            {{ platform.account.error_message }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <Avatar class="h-8 w-8">
                            <AvatarImage
                                v-if="platform.account.avatar_url"
                                :src="platform.account.avatar_url"
                            />
                            <AvatarFallback class="text-xs">
                                {{ getInitials(platform.account.display_label) }}
                            </AvatarFallback>
                        </Avatar>
                        <span
                            class="max-w-[120px] truncate text-sm font-medium"
                        >
                            {{ platform.account.display_label }}
                        </span>
                    </div>
                    <div class="flex items-center gap-1">
                        <!-- Reconnect button for disconnected accounts -->
                        <TooltipProvider
                            v-if="
                                showReconnect &&
                                isDisconnected(platform.account)
                            "
                        >
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8 text-amber-600 hover:text-amber-700"
                                        @click="openOAuthPopup(platform.value)"
                                    >
                                        <IconRefresh class="size-4" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>
                                        {{
                                            trans('accounts.reconnect_account')
                                        }}
                                    </p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                        <TooltipProvider
                            v-if="
                                showViewProfile &&
                                getProfileUrl(
                                    platform.value,
                                    platform.account.username,
                                    platform.account.platform_user_id,
                                )
                            "
                        >
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8"
                                        as-child
                                    >
                                        <a
                                            :href="
                                                getProfileUrl(
                                                    platform.value,
                                                    platform.account.username,
                                                    platform.account
                                                        .platform_user_id,
                                                )!
                                            "
                                            target="_blank"
                                        >
                                            <IconExternalLink class="size-4" />
                                        </a>
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>{{ trans('accounts.view_profile') }}</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                        <TooltipProvider v-if="showDisconnect">
                            <Tooltip>
                                <TooltipTrigger as-child>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="size-8"
                                        @click="
                                            emit(
                                                'disconnect',
                                                platform.account.id,
                                            )
                                        "
                                    >
                                        <IconTrash class="size-4" />
                                    </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    <p>{{ trans('accounts.disconnect') }}</p>
                                </TooltipContent>
                            </Tooltip>
                        </TooltipProvider>
                    </div>
                </div>
            </div>

            <!-- Not Connected State -->
            <div v-else class="border-t px-4 py-3">
                <Button
                    variant="outline"
                    class="w-full"
                    size="sm"
                    @click="openOAuthPopup(platform.value)"
                >
                    {{ trans('accounts.connect') }}
                </Button>
            </div>
        </div>
    </div>
</template>
