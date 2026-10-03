<script setup lang="ts">
import { IconChevronDown } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';

export interface ConnectHelpLink {
    label: string;
    href: string;
    testId: string;
}

defineProps<{
    links: readonly ConnectHelpLink[];
    testId: string;
}>();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button variant="ghost" size="lg" :data-testid="testId">
                {{ $t('channels.details.help') }}
                <IconChevronDown class="size-4" aria-hidden="true" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" class="w-auto">
            <DropdownMenuItem
                v-for="link in links"
                :key="link.testId"
                as-child
            >
                <a
                    :href="link.href"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="cursor-pointer"
                    :data-testid="link.testId"
                >
                    {{ $t(link.label) }}
                </a>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
