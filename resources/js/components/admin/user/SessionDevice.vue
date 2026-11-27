<script setup lang="ts">
import { computed } from 'vue';

import {
    browserIconUrl,
    isMonochromeOsIcon,
    osIconUrl,
    parseBrowserName,
    parseOsName,
} from '@/lib/userAgent';

/**
 * A session's browser and operating system, read from its user agent the
 * way the user's own Sessions settings show them: the browser's logo and
 * name "on" the system's logo and name. The raw user agent never shows.
 */
const props = withDefaults(
    defineProps<{
        userAgent: string | null;
        /** Off where the row already shows the browser's logo as its media. */
        showBrowserIcon?: boolean;
    }>(),
    { showBrowserIcon: true },
);

const browserName = computed(() => parseBrowserName(props.userAgent));
const osName = computed(() => parseOsName(props.userAgent));
const browserIcon = computed(() => browserIconUrl(props.userAgent));
const osIcon = computed(() => osIconUrl(props.userAgent));

const osIconClass = computed((): string => {
    if (isMonochromeOsIcon(props.userAgent)) {
        return 'size-4 shrink-0 object-contain dark:invert';
    }

    return 'size-4 shrink-0 object-contain';
});
</script>

<template>
    <span class="flex min-w-0 items-center gap-1.5 text-sm leading-tight text-foreground">
        <img
            v-if="showBrowserIcon"
            :src="browserIcon"
            alt=""
            class="size-4 shrink-0 object-contain"
        />
        <span class="truncate font-emphasis">{{ browserName }}</span>
        <template v-if="osName">
            <span class="text-muted-foreground">on</span>
            <img :src="osIcon" alt="" :class="osIconClass" />
            <span class="truncate text-muted-foreground">{{ osName }}</span>
        </template>
    </span>
</template>
