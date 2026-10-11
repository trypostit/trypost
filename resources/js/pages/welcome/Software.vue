<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import InputError from '@/components/InputError.vue';
import WelcomeChoiceCard from '@/components/welcome/WelcomeChoiceCard.vue';
import WelcomeContinueButton from '@/components/welcome/WelcomeContinueButton.vue';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { softwareArt, welcomeOptionArt } from '@/lib/welcomeOptions';
import { store } from '@/routes/app/welcome/software';

const props = defineProps<{
    software: string[];
    selected?: string | null;
}>();

const form = useForm({ previous_software: props.selected ?? '' });

const select = (value: string): void => {
    form.previous_software = value;
};

const submit = (): void => {
    if (!form.previous_software || form.processing) {
        return;
    }

    form.submit(store());
};
</script>

<template>
    <Head :title="$t('welcome.software_title')" />

    <WelcomeLayout
        :title="$t('welcome.software_title')"
        step="software"
    >
        <div class="grid gap-2 sm:grid-cols-2">
            <WelcomeChoiceCard
                v-for="option in software"
                :key="option"
                :label="$t(`welcome.software.${option}`)"
                :art="welcomeOptionArt(softwareArt, option)"
                :selected="form.previous_software === option"
                :testid="`welcome-software-${option}`"
                @select="select(option)"
            />
        </div>

        <template #actions>
            <WelcomeContinueButton
                :disabled="!form.previous_software || form.processing"
                testid="welcome-software-continue"
                @continue="submit"
            />
            <InputError :message="form.errors.previous_software" />
        </template>
    </WelcomeLayout>
</template>
