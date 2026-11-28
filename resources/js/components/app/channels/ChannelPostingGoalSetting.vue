<script setup lang="ts">
import { Stepper } from '@/components/ui/stepper';
import { MAX_GOAL } from '@/lib/app/postingSchedule';

const goal = defineModel<number>({ required: true });
</script>

<template>
    <section
        class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between"
    >
        <div class="min-w-0">
            <h2 class="text-base leading-5 font-emphasis text-foreground">
                {{ $t('channels.settings_page.goal_title') }}
            </h2>
            <p class="text-sm text-muted-foreground">
                {{ $t('channels.settings_page.goal_description') }}
            </p>
        </div>
        <Stepper
            v-model="goal"
            :max="MAX_GOAL"
            :decrease-label="$t('channels.settings_page.goal_decrease')"
            :increase-label="$t('channels.settings_page.goal_increase')"
            decrease-test-id="channel-goal-decrease"
            increase-test-id="channel-goal-increase"
            value-test-id="channel-goal-value"
            class="shrink-0 self-start md:self-auto"
        />
    </section>
</template>
