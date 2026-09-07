<script setup lang="ts">
import { IconCheck, IconChevronDown } from '@tabler/icons-vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useGuestLocale } from '@/composables/useGuestLocale';

const { locale, languages } = useGuestLocale();

const current = computed(() =>
    languages.value.find((language) => language.code === locale.value),
);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                class="h-8 gap-1.5 px-2 font-normal text-muted-foreground hover:text-foreground"
                data-testid="language-picker"
            >
                <img
                    v-if="current"
                    :src="current.flag"
                    :alt="current.name"
                    class="h-3.5 w-5 rounded-xs object-cover ring-1 ring-border"
                />
                <span>{{ current?.name }}</span>
                <IconChevronDown class="size-3.5 opacity-60" />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="max-h-80 w-44 overflow-y-auto">
            <DropdownMenuItem
                v-for="language in languages"
                :key="language.code"
                :class="language.code === locale ? 'bg-accent' : ''"
                :data-testid="`language-option-${language.code}`"
                @click="locale = language.code"
            >
                <img
                    :src="language.flag"
                    :alt="language.name"
                    class="h-3.5 w-5 rounded-xs object-cover ring-1 ring-border"
                />
                {{ language.name }}
                <IconCheck
                    v-if="language.code === locale"
                    class="ms-auto size-4 shrink-0"
                    stroke-width="2.5"
                />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
