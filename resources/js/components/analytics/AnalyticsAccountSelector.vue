<script setup lang="ts">
import { IconChevronDown } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { getPlatformLabel, getPlatformLogo } from '@/composables/usePlatformLogo';

import type { AnalyticsAccount } from './types';

const props = defineProps<{
    accounts: AnalyticsAccount[];
    selectedId: string | null;
}>();

const emit = defineEmits<{
    select: [accountId: string];
}>();

const open = ref(false);

const selected = computed(() =>
    props.accounts.find((a) => a.id === props.selectedId) ?? null,
);

const select = (account: AnalyticsAccount) => {
    emit('select', account.id);
    open.value = false;
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <Button
                type="button"
                variant="outline"
                role="combobox"
                :aria-expanded="open"
                :disabled="accounts.length === 0"
                class="h-auto w-full justify-start gap-3 px-3 py-2 text-left font-medium sm:w-auto sm:min-w-[240px]"
            >
                <template v-if="selected">
                    <div class="relative shrink-0">
                        <Avatar class="size-7 rounded-full border-2 border-foreground shadow-2xs">
                            <AvatarImage v-if="selected.avatar_url" :src="selected.avatar_url" :alt="selected.display_name" />
                            <AvatarFallback class="rounded-full bg-violet-100 text-xs font-bold text-foreground">
                                {{ selected.display_name?.charAt(0) }}
                            </AvatarFallback>
                        </Avatar>
                        <span class="absolute -bottom-1 -right-1 inline-flex size-4 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs">
                            <img
                                :src="getPlatformLogo(selected.platform)"
                                :alt="selected.platform"
                                class="size-full object-cover"
                            />
                        </span>
                    </div>
                    <span class="min-w-0 flex-1 truncate">
                        <span class="font-bold text-foreground">{{ selected.display_name }}</span>
                        <span v-if="selected.username" class="ml-1.5 text-xs font-medium text-foreground/60">
                            @{{ selected.username }}
                        </span>
                    </span>
                </template>
                <template v-else>
                    <span class="text-foreground/60">{{ $t('analytics.select_account') }}</span>
                </template>
                <IconChevronDown class="ml-auto size-4 shrink-0 text-foreground/60" />
            </Button>
        </PopoverTrigger>

        <PopoverContent class="w-[320px] overflow-hidden p-0" align="start">
            <Command class="rounded-[10px]">
                <CommandInput :placeholder="trans('analytics.search_account')" />
                <CommandList>
                    <CommandEmpty>{{ $t('analytics.no_accounts_match') }}</CommandEmpty>
                    <CommandGroup>
                        <CommandItem
                            v-for="account in accounts"
                            :key="account.id"
                            :value="account.id"
                            class="gap-3"
                            @select="select(account)"
                        >
                            <div class="relative shrink-0">
                                <Avatar class="size-9 rounded-full border-2 border-foreground shadow-2xs">
                                    <AvatarImage v-if="account.avatar_url" :src="account.avatar_url" :alt="account.display_name" />
                                    <AvatarFallback class="rounded-full bg-violet-100 font-bold text-foreground">
                                        {{ account.display_name?.charAt(0) }}
                                    </AvatarFallback>
                                </Avatar>
                                <span class="absolute -bottom-1 -right-1 inline-flex size-5 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs">
                                    <img
                                        :src="getPlatformLogo(account.platform)"
                                        :alt="account.platform"
                                        class="size-full object-cover"
                                    />
                                </span>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="truncate font-bold text-foreground">{{ account.display_name }}</p>
                                <p v-if="account.username" class="truncate text-xs font-medium text-foreground/60">
                                    @{{ account.username }}
                                </p>
                            </div>
                            <span class="sr-only">{{ getPlatformLabel(account.platform) }}</span>
                        </CommandItem>
                    </CommandGroup>
                </CommandList>
            </Command>
        </PopoverContent>
    </Popover>
</template>
