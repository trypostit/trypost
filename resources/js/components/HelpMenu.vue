<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    IconBrandDiscord,
    IconGift,
    IconLifebuoy,
    IconLifebuoyFilled,
    IconMessageChatbot,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { Component } from 'vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { SharedData } from '@/types';

interface HelpLink {
    key: string;
    label: string;
    href: string;
    icon: Component;
}

const page = usePage<SharedData>();

const showChatSupport = computed(() => page.props.selfHosted === false);

const helpLinks = computed<HelpLink[]>(() => [
    {
        key: 'docs',
        label: trans('sidebar.support.docs'),
        href: 'https://docs.trypost.it',
        icon: IconLifebuoy,
    },
]);

const communityLinks = computed<HelpLink[]>(() => [
    {
        key: 'discord',
        label: trans('sidebar.support.discord'),
        href: 'https://trypost.it/discord',
        icon: IconBrandDiscord,
    },
    {
        key: 'referral',
        label: trans('sidebar.support.referral'),
        href: 'https://affiliates.trypost.it/',
        icon: IconGift,
    },
]);

const openChat = (): void => {
    const crisp = window.$crisp;

    if (!crisp) {
        return;
    }

    crisp.push(['do', 'chat:show']);
    crisp.push(['do', 'chat:open']);
};
</script>

<template>
    <div class="fixed right-6 bottom-6 z-50">
        <DropdownMenu>
            <DropdownMenuTrigger as-child>
                <Button
                    type="button"
                    size="icon"
                    class="group size-12 rounded-full border-2 border-foreground shadow-md"
                    data-testid="help-menu-trigger"
                    :aria-label="$t('sidebar.help')"
                >
                    <IconLifebuoyFilled
                        class="size-7 transition-transform duration-300 group-hover:rotate-180 group-data-[state=open]:rotate-180"
                        aria-hidden="true"
                    />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                side="top"
                align="end"
                :side-offset="12"
                class="w-56"
                data-testid="help-menu-content"
            >
                <DropdownMenuLabel
                    class="px-2 py-1.5 text-xs font-medium tracking-normal text-muted-foreground normal-case"
                >
                    {{ $t('sidebar.help') }}
                </DropdownMenuLabel>
                <DropdownMenuGroup>
                    <DropdownMenuItem
                        v-if="showChatSupport"
                        class="cursor-pointer"
                        data-testid="help-menu-chat"
                        @click="openChat"
                    >
                        <IconMessageChatbot class="size-4" />
                        <span>{{ $t('sidebar.support.chat') }}</span>
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-for="link in helpLinks"
                        :key="link.key"
                        as-child
                    >
                        <a
                            :href="link.href"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="cursor-pointer"
                            :data-testid="`help-menu-${link.key}`"
                        >
                            <component :is="link.icon" class="size-4" />
                            <span>{{ link.label }}</span>
                        </a>
                    </DropdownMenuItem>
                </DropdownMenuGroup>

                <DropdownMenuSeparator />

                <DropdownMenuLabel
                    class="px-2 py-1.5 text-xs font-medium tracking-normal text-muted-foreground normal-case"
                >
                    {{ $t('sidebar.support.community') }}
                </DropdownMenuLabel>
                <DropdownMenuGroup>
                    <DropdownMenuItem
                        v-for="link in communityLinks"
                        :key="link.key"
                        as-child
                    >
                        <a
                            :href="link.href"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="cursor-pointer"
                            :data-testid="`help-menu-${link.key}`"
                        >
                            <component :is="link.icon" class="size-4" />
                            <span>{{ link.label }}</span>
                        </a>
                    </DropdownMenuItem>
                </DropdownMenuGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
