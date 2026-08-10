<script setup lang="ts">
import { IconCheck } from '@tabler/icons-vue';

import { Badge } from '@/components/ui/badge';

defineProps<{
    done: boolean;
    step: number;
    title: string;
    description: string;
    accentClass: string;
    skipped?: boolean;
}>();
</script>

<template>
    <section
        class="overflow-hidden rounded-2xl border-2 border-foreground bg-card shadow-2xs"
    >
        <header
            :class="[
                'flex items-center justify-between gap-4 border-b-2 border-foreground px-5 py-4 sm:px-6',
                skipped ? 'bg-muted' : done ? 'bg-emerald-100' : accentClass,
            ]"
        >
            <div class="flex min-w-0 items-center gap-3">
                <span
                    :class="[
                        'inline-flex size-8 shrink-0 items-center justify-center rounded-full border-2 border-foreground text-sm font-bold shadow-2xs',
                        done ? 'bg-emerald-300' : 'bg-card',
                    ]"
                >
                    <IconCheck
                        v-if="done && !skipped"
                        class="size-4"
                        stroke-width="3"
                    />
                    <template v-else>{{ step }}</template>
                </span>
                <div class="min-w-0">
                    <h2 class="text-base font-bold">
                        {{ title }}
                    </h2>
                    <p class="text-sm text-foreground/70">
                        {{ description }}
                    </p>
                </div>
            </div>
            <Badge
                class="shrink-0"
                :variant="done && !skipped ? 'success' : 'outline'"
            >
                {{
                    skipped
                        ? $t('onboarding.status.skipped')
                        : done
                          ? $t('onboarding.status.complete')
                          : $t('onboarding.status.todo')
                }}
            </Badge>
        </header>

        <div class="p-5 sm:p-6">
            <slot />
        </div>
    </section>
</template>
