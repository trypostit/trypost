<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    IconAlertCircle,
    IconBrandFacebook,
    IconDotsVertical,
    IconExternalLink,
    IconRefresh,
    IconSettings,
    IconTrash,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import ChannelAvatar from '@/components/ChannelAvatar.vue';
import GoalProgress from '@/components/publish/GoalProgress.vue';
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
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import { blank, filled } from '@/lib/helpers';
import { settings as settingsRoute } from '@/routes/app/channels';
import {
    accountTypeKey,
    isConnectionLost,
    type ChannelListRow,
    type ConnectedAccount,
} from '@/types/social-account';

const props = withDefaults(
    defineProps<{
        channel: ChannelListRow;
        canLinkFacebook?: boolean;
    }>(),
    {
        canLinkFacebook: false,
    },
);

const emit = defineEmits<{
    reconnect: [channel: ConnectedAccount];
    linkFacebook: [channel: ConnectedAccount];
    disconnect: [channel: ConnectedAccount];
}>();

const name = computed(
    () => props.channel.display_name || props.channel.username,
);

const lost = computed(() => isConnectionLost(props.channel));

const timezone = computed(() => props.channel.timezone_option);

/** UTC needs no offset next to it: "UTC GMT" says nothing more. */
const showsOffset = computed(() => timezone.value.label !== 'UTC');

const hasPostingTimes = computed(() => props.channel.posting_times_per_week > 0);

type Translate = (key: string) => string;

/**
 * The account type when the network has several ("LinkedIn Page"), else the network's name.
 * It takes the template's `$t`, so the type follows the loaded language.
 */
const networkName = (translate: Translate): string => {
    const typeKey = accountTypeKey(props.channel);

    if (filled(typeKey)) {
        return translate(typeKey);
    }

    return getPlatformLabel(props.channel.platform);
};

/** The network and the handle under the name: "Instagram · @trypost.it". */
const networkLine = (translate: Translate): string => {
    const network = networkName(translate);

    if (blank(props.channel.handle_label)) {
        return network;
    }

    return `${network} · ${props.channel.handle_label}`;
};

const reconnect = (): void => {
    emit('reconnect', props.channel);
};

const linkFacebook = (): void => {
    emit('linkFacebook', props.channel);
};

const disconnect = (): void => {
    emit('disconnect', props.channel);
};
</script>

<template>
    <div
        class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-2 px-4 py-3 md:channel-list-columns"
        :data-testid="`channel-row-${channel.id}`"
    >
        <div class="flex min-w-0 items-center gap-3">
            <ChannelAvatar
                :status="channel.status"
                :account-id="channel.id"
                :platform="channel.platform"
                :src="channel.avatar_url"
                :verified="channel.verified_badge"
                :name="name"
                :size="32"
                ring="card"
            />
            <div class="min-w-0">
                <p
                    class="truncate text-sm leading-tight font-emphasis text-foreground"
                    :data-testid="`channel-name-${channel.id}`"
                >
                    {{ name }}
                </p>
                <p
                    v-if="lost"
                    class="flex min-w-0 items-start gap-1 text-sm text-destructive"
                    :data-testid="`channel-connection-lost-${channel.id}`"
                >
                    <IconAlertCircle class="mt-[3px] size-3.5 shrink-0" />
                    <span>{{ $t('channels.list.connection_lost') }}</span>
                </p>
                <p
                    v-else
                    class="truncate text-sm text-muted-foreground"
                    :title="networkLine($t)"
                    :data-testid="`channel-network-${channel.id}`"
                >
                    {{ networkLine($t) }}
                </p>
            </div>
        </div>

        <div
            class="col-span-2 flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 text-sm max-md:order-3 max-md:ps-11 md:contents"
        >
            <p
                class="min-w-0 truncate text-foreground"
                :title="timezone.value"
                :data-testid="`channel-timezone-${channel.id}`"
            >
                {{ timezone.label }}
                <span v-if="showsOffset" class="text-muted-foreground">{{
                    timezone.offset
                }}</span>
            </p>
            <p
                v-if="hasPostingTimes"
                class="min-w-0 truncate text-foreground"
                :data-testid="`channel-posting-times-${channel.id}`"
            >
                {{
                    $t('channels.list.posting_times_per_week', {
                        count: String(channel.posting_times_per_week),
                    })
                }}
            </p>
            <p
                v-else
                class="min-w-0 truncate text-muted-foreground"
                :data-testid="`channel-posting-times-${channel.id}`"
            >
                {{ $t('channels.settings_page.day_empty') }}
            </p>
            <div class="flex min-w-0 text-muted-foreground">
                <GoalProgress
                    v-if="filled(channel.posting_goal)"
                    :channel-id="channel.id"
                    :sent="channel.sent_this_week"
                    :scheduled="channel.scheduled_this_week"
                    :goal="channel.posting_goal"
                    :test-id="`channel-goal-${channel.id}`"
                    :interactive="false"
                />
                <Link
                    v-else
                    :href="settingsRoute.url(channel.id)"
                    class="truncate rounded-md transition-control hover:text-foreground"
                    :data-testid="`channel-goal-set-${channel.id}`"
                >
                    {{ $t('channels.set_goal') }}
                </Link>
            </div>
        </div>

        <div class="flex shrink-0 items-center justify-end gap-1 max-md:order-2">
            <Button
                v-if="lost"
                variant="destructive"
                size="sm"
                class="max-md:hidden"
                :data-testid="`channel-reconnect-${channel.id}`"
                @click="reconnect"
            >
                <IconRefresh class="size-4" />
                {{ $t('channels.reconnect') }}
            </Button>
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button as-child variant="ghost" size="icon">
                        <Link
                            :href="settingsRoute.url(channel.id)"
                            :aria-label="$t('channels.settings')"
                            :data-testid="`channel-settings-${channel.id}`"
                        >
                            <IconSettings class="size-4" />
                        </Link>
                    </Button>
                </TooltipTrigger>
                <TooltipContent
                    :data-testid="`channel-settings-tooltip-${channel.id}`"
                >
                    {{ $t('channels.list.settings') }}
                </TooltipContent>
            </Tooltip>

            <Tooltip>
                <TooltipTrigger as-child>
                    <span class="inline-flex">
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
                                    v-if="canLinkFacebook"
                                    :data-testid="`channel-menu-link-facebook-${channel.id}`"
                                    @click="linkFacebook"
                                >
                                    <IconBrandFacebook class="size-4" />
                                    {{ $t('channels.link_facebook_page') }}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    :data-testid="`channel-menu-reconnect-${channel.id}`"
                                    @click="reconnect"
                                >
                                    <IconRefresh class="size-4" />
                                    {{ $t('channels.refresh_connection') }}
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    :data-testid="`channel-disconnect-${channel.id}`"
                                    @click="disconnect"
                                >
                                    <IconTrash class="size-4" />
                                    {{ $t('channels.disconnect') }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </span>
                </TooltipTrigger>
                <TooltipContent
                    :data-testid="`channel-menu-tooltip-${channel.id}`"
                >
                    {{ $t('channels.list.more_actions') }}
                </TooltipContent>
            </Tooltip>
        </div>

        <Button
            v-if="lost"
            variant="destructive"
            size="sm"
            class="col-span-2 order-4 md:hidden"
            :data-testid="`channel-reconnect-phone-${channel.id}`"
            @click="reconnect"
        >
            <IconRefresh class="size-4" />
            {{ $t('channels.reconnect') }}
        </Button>
    </div>
</template>
