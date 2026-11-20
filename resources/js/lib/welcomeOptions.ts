import { IconCircleOff, IconDots } from '@tabler/icons-vue';
import type { Component } from 'vue';

/**
 * A welcome option is drawn in a 36px tile: an emoji on a soft tint, or a
 * brand logo or a muted icon on a white tile that keeps its colors readable
 * in dark mode.
 */
export interface WelcomeOptionArt {
    emoji?: string;
    logo?: string;
    icon?: Component;
    tint: string;
}

const TINTS = {
    violet: 'bg-violet-100 dark:bg-violet-400/15',
    rose: 'bg-rose-100 dark:bg-rose-400/15',
    amber: 'bg-amber-100 dark:bg-amber-400/15',
    emerald: 'bg-emerald-100 dark:bg-emerald-400/15',
    sky: 'bg-sky-100 dark:bg-sky-400/15',
    pink: 'bg-pink-100 dark:bg-pink-400/15',
    orange: 'bg-orange-100 dark:bg-orange-400/15',
    teal: 'bg-teal-100 dark:bg-teal-400/15',
    slate: 'bg-muted',
} as const;

type Tint = keyof typeof TINTS;

const emoji = (character: string, tint: Tint): WelcomeOptionArt => ({
    emoji: character,
    tint: TINTS[tint],
});

const WHITE_TILE = 'bg-white ring-1 ring-border';

const logo = (name: string): WelcomeOptionArt => ({
    logo: `/images/competitors/${name}.svg`,
    tint: WHITE_TILE,
});

const icon = (component: Component): WelcomeOptionArt => ({
    icon: component,
    tint: WHITE_TILE,
});

const FALLBACK = emoji('✨', 'slate');

export const personaArt: Record<string, WelcomeOptionArt> = {
    creator: emoji('🎬', 'violet'),
    freelancer: emoji('💼', 'amber'),
    developer: emoji('💻', 'sky'),
    startup: emoji('🚀', 'rose'),
    agency: emoji('🎯', 'teal'),
    small_business: emoji('☕', 'emerald'),
    marketer: emoji('📣', 'orange'),
    online_store: emoji('🛍️', 'pink'),
    other: FALLBACK,
};

export const goalArt: Record<string, WelcomeOptionArt> = {
    save_time: emoji('⏱️', 'amber'),
    use_mcp: emoji('🔌', 'teal'),
    plan_calendar: emoji('🗓️', 'sky'),
    stay_on_brand: emoji('🎨', 'pink'),
    grow_audience: emoji('📈', 'emerald'),
    drive_sales: emoji('💰', 'orange'),
    manage_clients: emoji('🤝', 'rose'),
    other: FALLBACK,
};

export const softwareArt: Record<string, WelcomeOptionArt> = {
    buffer: logo('buffer'),
    hootsuite: logo('hootsuite'),
    later: logo('later'),
    metricool: logo('metricool'),
    typefully: logo('typefully'),
    hypefury: logo('hypefury'),
    publer: logo('publer'),
    postiz: logo('postiz'),
    sprout_social: logo('sprout_social'),
    socialbee: logo('socialbee'),
    none: icon(IconCircleOff),
    other: icon(IconDots),
};

export const welcomeOptionArt = (
    options: Record<string, WelcomeOptionArt>,
    value: string,
): WelcomeOptionArt => options[value] ?? FALLBACK;
