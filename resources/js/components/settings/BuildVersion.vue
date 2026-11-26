<script setup lang="ts">
import date from '@/date';
import { filled } from '@/lib/helpers';

type Translate = (key: string, replacements: Record<string, string>) => string;

const REPOSITORY_URL = 'https://github.com/trypostit/trypost';

const hasCommit = filled(__BUILD_COMMIT__);
const commitUrl = `${REPOSITORY_URL}/commit/${__BUILD_COMMIT__}`;

const version = (): string => {
    const builtAt = date.formatBuildDate(__BUILD_DATE__);

    if (hasCommit) {
        return `${builtAt} (${__BUILD_COMMIT__.slice(0, 7)})`;
    }

    return builtAt;
};

const label = (translate: Translate): string =>
    translate('settings.sidebar.version', { version: version() });
</script>

<template>
    <a
        v-if="hasCommit"
        :href="commitUrl"
        target="_blank"
        rel="noopener noreferrer"
        class="pointer-fine:cursor-alias rounded-md px-2 py-1.5 text-center text-[11px] whitespace-nowrap text-muted-foreground tabular-nums transition-colors hover:bg-sidebar-accent hover:text-sidebar-foreground focus-visible:ring-2 focus-visible:ring-sidebar-ring focus-visible:outline-hidden"
        data-testid="settings-build-info"
    >
        {{ label($t) }}
    </a>
    <span
        v-else
        class="px-2 py-1.5 text-center text-[11px] whitespace-nowrap text-muted-foreground tabular-nums"
        data-testid="settings-build-info"
    >
        {{ label($t) }}
    </span>
</template>
