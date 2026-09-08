<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { IconCheck, IconChevronDown } from '@tabler/icons-vue';
import { computed } from 'vue';

import { updateLanguage } from '@/actions/App/Http/Controllers/App/Settings/ProfileController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useGuestLocale } from '@/composables/useGuestLocale';
import type { Auth } from '@/types';

const page = usePage();
const { locale, languages } = useGuestLocale();

const isAuthenticated = computed(() => Boolean((page.props.auth as Auth).user));

const selected = computed(() =>
    isAuthenticated.value ? (page.props.locale as string) : locale.value,
);

const current = computed(() =>
    languages.value.find((language) => language.code === selected.value),
);

const select = (code: string): void => {
    if (code === selected.value) {
        return;
    }

    if (isAuthenticated.value) {
        router.put(updateLanguage.url(), { locale: code }, { preserveScroll: true });

        return;
    }

    locale.value = code;
};
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
                <template v-if="current">
                    <img
                        :src="current.flag"
                        :alt="current.name"
                        class="h-3.5 w-5 rounded-xs object-cover ring-1 ring-border"
                    />
                    <span>{{ current.name }}</span>
                </template>
                <IconChevronDown class="size-3.5 opacity-60" />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="max-h-80 w-44 overflow-y-auto">
            <DropdownMenuItem
                v-for="language in languages"
                :key="language.code"
                :class="language.code === selected ? 'bg-accent' : ''"
                :data-testid="`language-option-${language.code}`"
                @click="select(language.code)"
            >
                <img
                    :src="language.flag"
                    :alt="language.name"
                    class="h-3.5 w-5 rounded-xs object-cover ring-1 ring-border"
                />
                {{ language.name }}
                <IconCheck
                    v-if="language.code === selected"
                    class="ms-auto size-4 shrink-0"
                    stroke-width="2.5"
                />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
