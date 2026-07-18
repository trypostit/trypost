<script setup lang="ts">
import {
    IconCalendar,
    IconClock,
    IconHash,
    IconPhoto,
    IconUsers,
    IconVideo,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { onBeforeUnmount, onMounted, ref, computed } from 'vue';

defineProps<{
    title?: string;
    description?: string;
}>();

const slideKeys = ['calendar', 'scheduling', 'media', 'video', 'team', 'signatures'] as const;

const slideIcons = {
    calendar: IconCalendar,
    scheduling: IconClock,
    media: IconPhoto,
    video: IconVideo,
    team: IconUsers,
    signatures: IconHash,
};

const slides = computed(() =>
    slideKeys.map((key) => ({
        icon: slideIcons[key],
        title: trans(`auth.slides.${key}.title`),
        description: trans(`auth.slides.${key}.description`),
    })),
);

const activeIndex = ref(0);
const isPaused = ref(false);
let intervalId: ReturnType<typeof setInterval> | null = null;

const activeSlide = computed(() => slides.value[activeIndex.value]);

const goTo = (index: number) => {
    activeIndex.value = index;
    restartInterval();
};

const startInterval = () => {
    intervalId = setInterval(() => {
        if (!isPaused.value) {
            activeIndex.value = (activeIndex.value + 1) % slides.value.length;
        }
    }, 4000);
};

const restartInterval = () => {
    if (intervalId) {
        clearInterval(intervalId);
    }
    startInterval();
};

onMounted(() => {
    startInterval();
});

onBeforeUnmount(() => {
    if (intervalId) {
        clearInterval(intervalId);
    }
});

const platforms = [
    { name: 'LinkedIn', icon: '/images/accounts/linkedin.png' },
    { name: 'X', icon: '/images/accounts/x.png' },
    { name: 'Instagram', icon: '/images/accounts/instagram.png' },
    { name: 'Facebook', icon: '/images/accounts/facebook.png' },
    { name: 'TikTok', icon: '/images/accounts/tiktok.png' },
    { name: 'YouTube', icon: '/images/accounts/youtube.png' },
    { name: 'Threads', icon: '/images/accounts/threads.png' },
    { name: 'Pinterest', icon: '/images/accounts/pinterest.png' },
    { name: 'Bluesky', icon: '/images/accounts/bluesky.png' },
    { name: 'Mastodon', icon: '/images/accounts/mastodon.png' },
];
</script>

<template>
    <div class="grid min-h-svh grid-cols-1 lg:grid-cols-2">
        <div class="flex min-w-0 flex-col gap-4 p-6 md:p-10">
            <div class="flex items-start">
                <img
                    src="/images/trypost/logo-light.png"
                    alt="TryPost"
                    class="h-7"
                />
            </div>

            <div class="flex flex-1 items-center justify-center">
                <div class="w-full max-w-lg">
                    <div class="flex flex-col gap-6">
                        <div class="flex flex-col items-center gap-2 text-center">
                            <h1 v-if="title" class="text-2xl font-bold">{{ title }}</h1>
                            <p v-if="description" class="text-sm text-balance text-muted-foreground">
                                {{ description }}
                            </p>
                        </div>

                        <slot />
                    </div>
                </div>
            </div>
        </div>

        <div
            class="relative hidden overflow-hidden border-l-2 border-foreground bg-accent lg:sticky lg:top-0 lg:block lg:h-svh lg:self-start"
            @mouseenter="isPaused = true"
            @mouseleave="isPaused = false"
        >
            <!-- Soft violet glow blobs for ambient depth (off-canvas). -->
            <div class="pointer-events-none absolute -top-24 -right-24 size-[440px] rounded-full bg-violet-200/50 blur-3xl" />
            <div class="pointer-events-none absolute -bottom-32 -left-32 size-[440px] rounded-full bg-fuchsia-200/40 blur-3xl" />

            <!-- Dot pattern overlay (subtle). -->
            <div
                class="pointer-events-none absolute inset-0 opacity-[0.06]"
                style="background-image: radial-gradient(circle, #0a0a0a 1px, transparent 1px); background-size: 28px 28px;"
            />

            <div class="relative flex h-full flex-col items-center justify-center px-12 xl:px-16">
                <!-- Mockup card carousel -->
                <div class="relative h-[280px] w-full max-w-md">
                    <template v-for="(slide, index) in slides" :key="index">
                        <Transition
                            enter-active-class="transition-all duration-500 ease-out"
                            leave-active-class="transition-all duration-500 ease-out"
                            enter-from-class="opacity-0 translate-y-4"
                            enter-to-class="opacity-100 translate-y-0"
                            leave-from-class="opacity-100 translate-y-0"
                            leave-to-class="opacity-0 -translate-y-4"
                        >
                            <div
                                v-if="activeIndex === index"
                                class="absolute inset-0 flex items-center justify-center"
                            >
                                <div class="w-full overflow-hidden rounded-xl border-2 border-foreground bg-card shadow-xl -rotate-1">
                                    <!-- Title bar with traffic lights + live badge -->
                                    <div class="flex items-center gap-3 border-b-2 border-foreground bg-muted px-4 py-2.5">
                                        <div class="flex gap-1.5">
                                            <span class="size-3 rounded-full border border-foreground bg-rose-300" />
                                            <span class="size-3 rounded-full border border-foreground bg-amber-300" />
                                            <span class="size-3 rounded-full border border-foreground bg-emerald-300" />
                                        </div>
                                        <div class="ml-2 truncate text-[10px] font-bold uppercase tracking-widest text-muted-foreground">
                                            trypost.it
                                        </div>
                                        <span class="ml-auto inline-flex items-center gap-1.5 rounded-md border-2 border-foreground bg-foreground px-2 py-0.5 text-[10px] font-black uppercase tracking-widest text-background shadow-2xs">
                                            <span class="relative flex size-1.5">
                                                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-emerald-400/80" />
                                                <span class="relative inline-flex size-1.5 rounded-full bg-emerald-400" />
                                            </span>
                                            Live
                                        </span>
                                    </div>

                                    <!-- Body: feature icon -->
                                    <div class="flex items-center justify-center bg-card py-8">
                                        <div class="flex size-20 items-center justify-center rounded-2xl border-2 border-foreground bg-violet-200 shadow-sm -rotate-2">
                                            <component :is="slide.icon" class="size-10 text-foreground" />
                                        </div>
                                    </div>

                                    <!-- Platform strip -->
                                    <div class="flex flex-wrap justify-center gap-1.5 border-t-2 border-foreground/15 bg-card px-4 py-3">
                                        <img
                                            v-for="platform in platforms"
                                            :key="platform.name"
                                            :src="platform.icon"
                                            :alt="platform.name"
                                            class="size-7 rounded-full border-2 border-foreground bg-card p-0.5 shadow-2xs"
                                        />
                                    </div>
                                </div>
                            </div>
                        </Transition>
                    </template>
                </div>

                <!-- Text content -->
                <div class="mt-10 w-full max-w-md text-center">
                    <div class="relative h-[100px]">
                        <TransitionGroup
                            enter-active-class="transition-all duration-400 ease-out"
                            leave-active-class="transition-all duration-300 ease-in"
                            enter-from-class="opacity-0 translate-y-2"
                            enter-to-class="opacity-100 translate-y-0"
                            leave-from-class="opacity-100"
                            leave-to-class="opacity-0"
                        >
                            <div :key="activeIndex" class="absolute inset-x-0 top-0">
                                <h3 class="h3 text-foreground">
                                    {{ activeSlide.title }}
                                </h3>
                                <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-foreground/70">
                                    {{ activeSlide.description }}
                                </p>
                            </div>
                        </TransitionGroup>
                    </div>

                    <!-- Dots -->
                    <div class="flex items-center justify-center gap-2">
                        <button
                            v-for="(_, index) in slides"
                            :key="index"
                            class="group relative flex h-5 cursor-pointer items-center justify-center"
                            @click="goTo(index)"
                        >
                            <span
                                class="block h-1.5 rounded-full border border-foreground transition-all duration-300"
                                :class="activeIndex === index
                                    ? 'w-6 bg-foreground'
                                    : 'w-1.5 bg-card group-hover:bg-foreground/30'
                                "
                            />
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
