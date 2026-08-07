<script setup lang="ts">
import { Link, router, usePage, usePoll } from '@inertiajs/vue3';
import { IconListCheck } from '@tabler/icons-vue';
import { computed, watch } from 'vue';

import { useWorkspaceEcho } from '@/composables/echo/useWorkspaceEcho';
import { useActiveUrl } from '@/composables/useActiveUrl';
import { onboarding } from '@/routes/app';
import type { OnboardingResidual } from '@/types';

const page = usePage();
const { urlIsActive } = useActiveUrl();

const residual = computed<OnboardingResidual | false>(
    () => page.props.onboardingResidual,
);

const progress = computed(() => {
    if (residual.value === false) {
        return 0;
    }

    const { completed, total } = residual.value;

    return total > 0 ? Math.round((completed / total) * 100) : 0;
});

// Echo is the fast path; the slow poll covers Reverb outages while the banner
// shows. Skip on the onboarding page — that page already reloads residual with
// status. Keep the poll sparse — every tick re-runs the current page's
// controller server-side, not just the residual prop.
useWorkspaceEcho('.onboarding.status.updated', () => {
    if (
        page.props.onboardingResidual === false ||
        page.component === 'onboarding/Index'
    ) {
        return;
    }

    router.reload({ only: ['onboardingResidual'] });
});

const { start, stop } = usePoll(
    30000,
    { only: ['onboardingResidual'] },
    { autoStart: false },
);

watch(
    () => [page.props.onboardingResidual, page.component] as const,
    ([current, component]) => {
        if (current === false || component === 'onboarding/Index') {
            stop();

            return;
        }

        start();
    },
    { immediate: true },
);
</script>

<template>
    <div
        v-if="residual !== false"
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
                    {{ residual.completed }}/{{ residual.total }}
                </span>
            </div>
            <div
                class="mt-2.5 h-1.5 overflow-hidden rounded-full border border-foreground bg-card"
                role="progressbar"
                :aria-valuenow="residual.completed"
                :aria-valuemin="0"
                :aria-valuemax="residual.total"
            >
                <div
                    class="h-full bg-foreground transition-[width]"
                    :style="{ width: `${progress}%` }"
                />
            </div>
        </Link>
    </div>
</template>
