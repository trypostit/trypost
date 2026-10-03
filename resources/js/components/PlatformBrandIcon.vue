<script setup lang="ts">
import {
    IconBrandBluesky,
    IconBrandDiscord,
    IconBrandFacebook,
    IconBrandGoogleFilled,
    IconBrandInstagram,
    IconBrandLinkedin,
    IconBrandMastodon,
    IconBrandPinterest,
    IconBrandTelegram,
    IconBrandThreads,
    IconBrandTiktok,
    IconBrandX,
    IconBrandYoutubeFilled,
} from '@tabler/icons-vue';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        platform: string;
        colored?: boolean;
        inverse?: boolean;
    }>(),
    { colored: false, inverse: false },
);

const BRAND_COLORS: Record<string, string> = {
    linkedin: 'text-[#0A66C2]',
    'linkedin-page': 'text-[#0A66C2]',
    instagram: 'text-[#E1306C]',
    'instagram-facebook': 'text-[#E1306C]',
    facebook: 'text-[#1877F2]',
    youtube: 'text-[#FF0000]',
    pinterest: 'text-[#E60023]',
    bluesky: 'text-[#1185FE]',
    mastodon: 'text-[#6364FF]',
    telegram: 'text-[#26A5E4]',
    discord: 'text-[#5865F2]',
    google_business: 'text-[#4285F4]',
};

const icons = {
    linkedin: IconBrandLinkedin,
    'linkedin-page': IconBrandLinkedin,
    x: IconBrandX,
    tiktok: IconBrandTiktok,
    youtube: IconBrandYoutubeFilled,
    facebook: IconBrandFacebook,
    instagram: IconBrandInstagram,
    'instagram-facebook': IconBrandInstagram,
    threads: IconBrandThreads,
    pinterest: IconBrandPinterest,
    bluesky: IconBrandBluesky,
    mastodon: IconBrandMastodon,
    telegram: IconBrandTelegram,
    discord: IconBrandDiscord,
    google_business: IconBrandGoogleFilled,
} as const;

const icon = computed(() => icons[props.platform as keyof typeof icons]);
</script>

<template>
    <component
        :is="icon"
        v-if="icon"
        :class="[
            'size-4.5',
            inverse
                ? 'text-white'
                : colored
                  ? (BRAND_COLORS[platform] ?? 'text-foreground')
                  : 'text-muted-foreground',
        ]"
    />
</template>
