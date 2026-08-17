<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
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
    IconCheck,
    IconDots,
    IconListSearch,
    IconSparkles,
    IconUser,
    IconUsers,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { FunctionalComponent } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { store } from '@/routes/app/welcome/referral-source';

const props = defineProps<{
    sources: string[];
    selected?: string | null;
}>();

const form = useForm<{ referral_source: string }>({
    referral_source: props.selected ?? '',
});

type SourceMeta = {
    icon?: FunctionalComponent;
    logo?: string;
    iconClass: string;
    badge: string;
};

const sourceMeta: Record<string, SourceMeta> = {
    google: {
        logo: '/images/social/google.svg',
        iconClass: '',
        badge: 'bg-white',
    },
    x: {
        icon: IconBrandXFilled,
        iconClass: 'text-white',
        badge: 'bg-black',
    },
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
    other: {
        icon: IconDots,
        iconClass: 'text-foreground',
        badge: 'bg-muted',
    },
};

const metaFor = (value: string): SourceMeta =>
    sourceMeta[value] ?? {
        icon: IconDots,
        iconClass: 'text-foreground',
        badge: 'bg-muted',
    };

const sourceLabel = (value: string): string =>
    trans(`welcome.referral_source.${value}`);

const isSelected = (value: string): boolean => form.referral_source === value;

const select = (value: string): void => {
    form.referral_source = value;
};

const submit = (): void => {
    if (form.referral_source === '' || form.processing) {
        return;
    }

    form.submit(store());
};
</script>

<template>
    <Head :title="$t('welcome.referral_source_title')" />

    <WelcomeLayout
        :title="$t('welcome.referral_source_title')"
        :description="$t('welcome.referral_source_description')"
        :step="3"
        size="4xl"
    >
        <div class="flex flex-wrap justify-center gap-2.5">
            <button
                v-for="source in sources"
                :key="source"
                type="button"
                :aria-pressed="isSelected(source)"
                :data-testid="`welcome-source-${source}`"
                :class="[
                    'inline-flex cursor-pointer items-center gap-3 rounded-full border-2 border-foreground py-2.5 ps-2.5 pe-5 text-start shadow-2xs transition-shadow hover:shadow-md',
                    isSelected(source) ? 'bg-violet-100' : 'bg-card',
                ]"
                @click="select(source)"
            >
                <span
                    :class="[
                        'inline-flex size-11 shrink-0 items-center justify-center rounded-full border-2 border-foreground shadow-2xs',
                        metaFor(source).badge,
                    ]"
                >
                    <img
                        v-if="metaFor(source).logo"
                        :src="metaFor(source).logo"
                        :alt="sourceLabel(source)"
                        class="size-6"
                    />
                    <component
                        :is="metaFor(source).icon"
                        v-else
                        :class="[metaFor(source).iconClass, 'size-6']"
                        stroke-width="2"
                    />
                </span>
                <span
                    class="text-sm font-bold tracking-tight text-foreground sm:text-base"
                >
                    {{ sourceLabel(source) }}
                </span>
                <span
                    v-if="isSelected(source)"
                    class="inline-flex size-5 shrink-0 items-center justify-center rounded-full border-2 border-foreground bg-foreground"
                >
                    <IconCheck
                        class="size-3 text-background"
                        stroke-width="3"
                    />
                </span>
            </button>
        </div>

        <div class="mx-auto flex w-full max-w-sm flex-col items-center gap-3">
            <InputError :message="form.errors.referral_source" />
            <Button
                type="button"
                size="lg"
                class="w-full rounded-full"
                :disabled="form.referral_source === '' || form.processing"
                data-testid="welcome-referral-continue"
                dusk="welcome-referral-continue"
                @click="submit"
            >
                {{ $t('welcome.continue') }}
            </Button>
        </div>
    </WelcomeLayout>
</template>
