<script setup lang="ts">
import {
    IconBrandDiscord,
    IconGift,
    IconLifebuoy,
    IconLifebuoyFilled,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { Component } from 'vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

interface HelpLink {
    key: string;
    label: string;
    href: string;
    icon: Component;
}

const links = computed<HelpLink[]>(() => [
    {
        key: 'referral',
        label: trans('sidebar.support.referral'),
        href: 'https://affiliates.trypost.it/',
        icon: IconGift,
    },
    {
        key: 'discord',
        label: trans('sidebar.support.discord'),
        href: 'https://trypost.it/discord',
        icon: IconBrandDiscord,
    },
    {
        key: 'docs',
        label: trans('sidebar.support.docs'),
        href: 'https://docs.trypost.it',
        icon: IconLifebuoy,
    },
]);
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
                <DropdownMenuItem
                    v-for="link in links"
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
            </DropdownMenuContent>
        </DropdownMenu>
    </div>
</template>
