<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { IconMoon, IconSun } from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import PreferencesController from '@/actions/App/Http/Controllers/App/Settings/PreferencesController';
import { applyTheme, type Theme } from '@/preferences';
import type { SharedData } from '@/types';

type ResolvedTheme = Exclude<Theme, 'system'>;

const page = usePage<SharedData>();

const resolve = (theme: Theme | undefined): ResolvedTheme => {
    if (theme === 'light' || theme === 'dark') {
        return theme;
    }

    return document.documentElement.classList.contains('dark')
        ? 'dark'
        : 'light';
};

const current = ref<ResolvedTheme>(resolve(page.props.auth.user?.theme));

const currentIcon = computed(() =>
    current.value === 'dark' ? IconMoon : IconSun,
);

const nextTheme = computed<ResolvedTheme>(() =>
    current.value === 'dark' ? 'light' : 'dark',
);

const toggleTheme = (): void => {
    const theme = nextTheme.value;

    current.value = theme;
    applyTheme(theme);
    router.patch(
        PreferencesController.update.url(),
        { theme },
        { preserveScroll: true, preserveState: true },
    );
};
</script>

<template>
    <button
        type="button"
        :aria-label="$t(`settings.preferences.theme.${nextTheme}`)"
        :title="$t(`settings.preferences.theme.${nextTheme}`)"
        data-testid="theme-toggle"
        class="flex size-8 items-center justify-center rounded-lg border border-border bg-card text-foreground transition-control hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
        @click="toggleTheme"
    >
        <component :is="currentIcon" class="size-4" />
    </button>
</template>
