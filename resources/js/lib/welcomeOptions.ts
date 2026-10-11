/** A welcome option is drawn as an emoji on a soft tint in a 36px tile. */
export interface WelcomeOptionArt {
    emoji: string;
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
    buffer: emoji('B', 'slate'),
    hootsuite: emoji('H', 'amber'),
    later: emoji('L', 'violet'),
    metricool: emoji('M', 'sky'),
    typefully: emoji('T', 'teal'),
    hypefury: emoji('H', 'orange'),
    publer: emoji('P', 'sky'),
    postiz: emoji('P', 'violet'),
    sprout_social: emoji('S', 'emerald'),
    socialbee: emoji('S', 'amber'),
    none: emoji('🌱', 'emerald'),
    other: FALLBACK,
};

export const welcomeOptionArt = (
    options: Record<string, WelcomeOptionArt>,
    value: string,
): WelcomeOptionArt => options[value] ?? FALLBACK;
