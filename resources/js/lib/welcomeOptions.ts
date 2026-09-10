import {
    IconArticle,
    IconBrandGithub,
    IconBrandInstagram,
    IconBrandLinkedin,
    IconBrandProducthunt,
    IconBrandReddit,
    IconBrandThreads,
    IconBrandTiktokFilled,
    IconBrandXFilled,
    IconBrandYcombinator,
    IconBrandYoutubeFilled,
    IconBriefcase,
    IconBuildingSkyscraper,
    IconBuildingStore,
    IconCalendar,
    IconClock,
    IconCode,
    IconCoin,
    IconCompass,
    IconDots,
    IconListSearch,
    IconPalette,
    IconPlug,
    IconRocket,
    IconShoppingBag,
    IconSparkles,
    IconSpeakerphone,
    IconTrendingUp,
    IconUser,
    IconUsers,
    IconUsersGroup,
} from '@tabler/icons-vue';
import type { FunctionalComponent } from 'vue';

export interface WelcomeOptionMeta {
    icon?: FunctionalComponent;
    logo?: string;
    iconClass: string;
    badge: string;
}

const FALLBACK: WelcomeOptionMeta = {
    icon: IconDots,
    iconClass: 'text-foreground',
    badge: 'bg-muted',
};

export const personaMeta: Record<string, WelcomeOptionMeta> = {
    creator: {
        icon: IconUser,
        iconClass: 'text-rose-700',
        badge: 'bg-rose-100',
    },
    freelancer: {
        icon: IconBriefcase,
        iconClass: 'text-amber-700',
        badge: 'bg-amber-100',
    },
    developer: {
        icon: IconCode,
        iconClass: 'text-cyan-700',
        badge: 'bg-cyan-100',
    },
    startup: {
        icon: IconRocket,
        iconClass: 'text-violet-700',
        badge: 'bg-violet-100',
    },
    agency: {
        icon: IconBuildingSkyscraper,
        iconClass: 'text-blue-700',
        badge: 'bg-blue-100',
    },
    small_business: {
        icon: IconBuildingStore,
        iconClass: 'text-emerald-700',
        badge: 'bg-emerald-100',
    },
    marketer: {
        icon: IconSpeakerphone,
        iconClass: 'text-fuchsia-700',
        badge: 'bg-fuchsia-100',
    },
    online_store: {
        icon: IconShoppingBag,
        iconClass: 'text-teal-700',
        badge: 'bg-teal-100',
    },
    other: FALLBACK,
};

export const goalMeta: Record<string, WelcomeOptionMeta> = {
    save_time: {
        icon: IconClock,
        iconClass: 'text-amber-700',
        badge: 'bg-amber-100',
    },
    ai_content: {
        icon: IconSparkles,
        iconClass: 'text-violet-700',
        badge: 'bg-violet-100',
    },
    use_mcp: {
        icon: IconPlug,
        iconClass: 'text-teal-700',
        badge: 'bg-teal-100',
    },
    plan_calendar: {
        icon: IconCalendar,
        iconClass: 'text-blue-700',
        badge: 'bg-blue-100',
    },
    stay_on_brand: {
        icon: IconPalette,
        iconClass: 'text-orange-700',
        badge: 'bg-orange-100',
    },
    grow_audience: {
        icon: IconTrendingUp,
        iconClass: 'text-rose-700',
        badge: 'bg-rose-100',
    },
    drive_sales: {
        icon: IconCoin,
        iconClass: 'text-emerald-700',
        badge: 'bg-emerald-100',
    },
    manage_clients: {
        icon: IconUsersGroup,
        iconClass: 'text-cyan-700',
        badge: 'bg-cyan-100',
    },
    just_exploring: {
        icon: IconCompass,
        iconClass: 'text-sky-700',
        badge: 'bg-sky-100',
    },
    other: FALLBACK,
};

export const referralSourceMeta: Record<string, WelcomeOptionMeta> = {
    google: {
        logo: '/images/social/google.svg',
        iconClass: '',
        badge: 'bg-white',
    },
    x: { icon: IconBrandXFilled, iconClass: 'text-white', badge: 'bg-black' },
    linkedin: {
        icon: IconBrandLinkedin,
        iconClass: 'text-white',
        badge: 'bg-[#0A66C2]',
    },
    youtube: {
        icon: IconBrandYoutubeFilled,
        iconClass: 'text-white',
        badge: 'bg-[#FF0000]',
    },
    tiktok: {
        icon: IconBrandTiktokFilled,
        iconClass: 'text-white',
        badge: 'bg-black',
    },
    instagram: {
        icon: IconBrandInstagram,
        iconClass: 'text-white',
        badge: 'bg-gradient-to-br from-[#f9ce34] via-[#ee2a7b] to-[#6228d7]',
    },
    threads: {
        icon: IconBrandThreads,
        iconClass: 'text-white',
        badge: 'bg-black',
    },
    reddit: {
        icon: IconBrandReddit,
        iconClass: 'text-white',
        badge: 'bg-[#FF4500]',
    },
    product_hunt: {
        icon: IconBrandProducthunt,
        iconClass: 'text-[#FF6154]',
        badge: 'bg-white',
    },
    github: {
        icon: IconBrandGithub,
        iconClass: 'text-white',
        badge: 'bg-black',
    },
    hacker_news: {
        icon: IconBrandYcombinator,
        iconClass: 'text-white',
        badge: 'bg-[#FF6600]',
    },
    directories: {
        icon: IconListSearch,
        iconClass: 'text-sky-800',
        badge: 'bg-sky-100',
    },
    ai_assistant: {
        icon: IconSparkles,
        iconClass: 'text-violet-700',
        badge: 'bg-violet-100',
    },
    friend: {
        icon: IconUsers,
        iconClass: 'text-emerald-700',
        badge: 'bg-emerald-100',
    },
    founder: {
        icon: IconUser,
        iconClass: 'text-orange-800',
        badge: 'bg-orange-100',
    },
    blog: {
        icon: IconArticle,
        iconClass: 'text-amber-800',
        badge: 'bg-amber-100',
    },
    other: FALLBACK,
};

export const welcomeOptionMeta = (
    options: Record<string, WelcomeOptionMeta>,
    value: string,
): WelcomeOptionMeta => options[value] ?? FALLBACK;
