<script setup lang="ts">
import { IconFlame } from '@tabler/icons-vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

import { VIRAL_HEATMAP, viralBand, viralColor } from '@/lib/viralScore';

const props = withDefaults(
    defineProps<{
        score: number;
        /** Play the "analyzing…" intro before revealing (the aha moment). */
        autoAnalyze?: boolean;
        size?: number;
    }>(),
    {
        autoAnalyze: true,
        size: 132,
    },
);

const displayed = ref(0);
const analyzing = ref(false);
let frame: number | null = null;
let analyzeTimer: ReturnType<typeof setTimeout> | null = null;

const stroke = 10;
const band = computed(() => viralBand(Math.round(displayed.value)));
const color = computed(() => viralColor(Math.round(displayed.value)));
const rounded = computed(() => Math.round(displayed.value));

// Heatmap ring: the filled arc runs the cool→hot ramp and ends at the tip, so
// it visibly "heats up" while it grows. The remainder is a neutral track.
const ringStyle = computed(() => {
    const deg = (displayed.value / 100) * 360;
    const [c0, c1, c2, c3, c4] = VIRAL_HEATMAP;
    const stops = [
        `${c0} 0deg`,
        `${c1} ${deg * 0.28}deg`,
        `${c2} ${deg * 0.52}deg`,
        `${c3} ${deg * 0.76}deg`,
        `${c4} ${deg}deg`,
        `var(--color-muted) ${deg}deg`,
    ].join(', ');
    const donut = `radial-gradient(farthest-side, transparent calc(100% - ${stroke}px), #000 calc(100% - ${stroke}px))`;

    return {
        background: `conic-gradient(from -90deg, ${stops})`,
        mask: donut,
        WebkitMask: donut,
    };
});

const easeOutCubic = (t: number): number => 1 - (1 - t) ** 3;

const cancelFrame = (): void => {
    if (frame !== null) {
        cancelAnimationFrame(frame);
        frame = null;
    }
};

const animateTo = (target: number, duration: number): void => {
    cancelFrame();
    const from = displayed.value;
    const delta = target - from;
    const start = performance.now();

    const tick = (now: number): void => {
        const t = Math.min(1, (now - start) / duration);
        displayed.value = from + delta * easeOutCubic(t);
        if (t < 1) {
            frame = requestAnimationFrame(tick);
        } else {
            displayed.value = target;
            frame = null;
        }
    };

    frame = requestAnimationFrame(tick);
};

const reveal = (withIntro: boolean): void => {
    if (analyzeTimer) {
        clearTimeout(analyzeTimer);
        analyzeTimer = null;
    }

    if (withIntro) {
        analyzing.value = true;
        displayed.value = 0;
        analyzeTimer = setTimeout(() => {
            analyzing.value = false;
            animateTo(props.score, 1400);
        }, 850);
        return;
    }

    animateTo(props.score, 550);
};

onMounted(() => reveal(props.autoAnalyze));

// Live recompute (editor): glide to the new score without replaying the intro.
watch(
    () => props.score,
    () => {
        if (!analyzing.value) {
            animateTo(props.score, 550);
        }
    },
);

onBeforeUnmount(() => {
    cancelFrame();
    if (analyzeTimer) {
        clearTimeout(analyzeTimer);
    }
});
</script>

<template>
    <div class="flex items-center gap-4">
        <div
            class="relative shrink-0"
            :style="{ width: `${size}px`, height: `${size}px` }"
        >
            <div class="absolute inset-0 rounded-full" :style="ringStyle" />
            <div
                class="absolute inset-0 flex flex-col items-center justify-center"
            >
                <Transition
                    mode="out-in"
                    enter-active-class="transition-opacity duration-300"
                    enter-from-class="opacity-0"
                    leave-active-class="transition-opacity duration-150"
                    leave-to-class="opacity-0"
                >
                    <IconFlame
                        v-if="analyzing"
                        class="size-8 animate-pulse"
                        :style="{ color }"
                    />
                    <div v-else class="flex items-baseline" :style="{ color }">
                        <span
                            class="text-3xl font-black tracking-tight tabular-nums"
                            >{{ rounded }}</span
                        >
                        <span class="text-base font-bold opacity-70">%</span>
                    </div>
                </Transition>
            </div>
        </div>

        <div class="min-w-0">
            <p
                class="flex items-center gap-1.5 text-xs font-semibold tracking-wide uppercase"
                :style="{ color }"
            >
                <IconFlame class="size-3.5" />
                {{ $t('posts.viral.title') }}
            </p>
            <p
                class="mt-0.5 text-lg font-bold tracking-tight"
                :style="{ color }"
            >
                {{
                    analyzing
                        ? $t('posts.viral.analyzing')
                        : $t(`posts.viral.band_${band}`)
                }}
            </p>
            <p class="mt-0.5 text-sm text-muted-foreground">
                {{
                    analyzing
                        ? $t('posts.viral.analyzing_hint')
                        : $t('posts.viral.subtitle')
                }}
            </p>
        </div>
    </div>
</template>
