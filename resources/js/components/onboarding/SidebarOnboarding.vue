<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { IconListCheck } from '@tabler/icons-vue';
import { computed } from 'vue';

import { useOnboardingLiveReload } from '@/composables/useOnboardingLiveReload';
import { useActiveUrl } from '@/composables/useActiveUrl';
import { onboarding } from '@/routes/app';
import type { OnboardingProgress } from '@/types';

const page = usePage();
const { urlIsActive } = useActiveUrl();

const onboardingProgress = computed<OnboardingProgress | false | undefined>(
    () => page.props.onboardingProgress,
);

const progressPercent = computed(() => {
    if (! onboardingProgress.value) {
        return 0;
    }

    const { completed, total } = onboardingProgress.value;

    return total > 0 ? Math.round((completed / total) * 100) : 0;
});

const liveEnabled = computed(
    () =>
        Boolean(onboardingProgress.value) &&
        page.component !== 'onboarding/Index',
);

useOnboardingLiveReload({
    only: ['onboardingProgress'],
    enabled: liveEnabled,
});
</script>

<template>
    <div
        v-if="onboardingProgress"
        class="px-1 pb-1 group-data-[collapsible=icon]:hidden"
    >
        <Link
            :href="onboarding.url()"
            data-testid="sidebar-onboarding"
            :class="[
                'block rounded-lg border-2 border-foreground p-3 shadow-2xs transition-colors',
                urlIsActive(onboarding.url())
                    ? 'bg-amber-200'
                    : 'bg-amber-100 hover:bg-amber-200',
            ]"
        >
            <div class="flex items-start justify-between gap-2">
                <div class="flex min-w-0 items-center gap-2">
                    <span
                        class="inline-flex size-7 shrink-0 items-center justify-center rounded-md border-2 border-foreground bg-card shadow-2xs"
                    >
                        <IconListCheck
                            class="size-4 text-amber-800"
                            stroke-width="2.5"
                        />
                    </span>
                    <div class="min-w-0">
                        <p class="truncate text-sm font-bold text-foreground">
                            {{ $t('sidebar.onboarding') }}
                        </p>
                        <p class="truncate text-xs text-foreground/70">
                            {{ $t('sidebar.onboarding_hint') }}
                        </p>
                    </div>
                </div>
                <span
                    class="shrink-0 rounded-full border-2 border-foreground bg-card px-1.5 py-0.5 text-[10px] font-bold tabular-nums"
                >
                    {{ onboardingProgress.completed }}/{{
                        onboardingProgress.total
                    }}
                </span>
            </div>
            <div
                class="mt-2.5 h-1.5 overflow-hidden rounded-full border border-foreground bg-card"
                role="progressbar"
                :aria-label="$t('sidebar.onboarding')"
                :aria-valuenow="onboardingProgress.completed"
                :aria-valuemin="0"
                :aria-valuemax="onboardingProgress.total"
            >
                <div
                    class="h-full bg-foreground transition-[width]"
                    :style="{ width: `${progressPercent}%` }"
                />
            </div>
        </Link>
    </div>
</template>
