<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

import InputError from '@/components/InputError.vue';
import WelcomeChoiceCard from '@/components/welcome/WelcomeChoiceCard.vue';
import WelcomeContinueButton from '@/components/welcome/WelcomeContinueButton.vue';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { personaArt, welcomeOptionArt } from '@/lib/welcomeOptions';
import { store } from '@/routes/app/welcome/persona';

const props = defineProps<{
    personas: string[];
    selected?: string | null;
}>();

const form = useForm({ persona: props.selected ?? '' });

const select = (value: string): void => {
    form.persona = value;
};

const submit = (): void => {
    if (!form.persona || form.processing) {
        return;
    }

    form.submit(store());
};
</script>

<template>
    <Head :title="$t('welcome.title')" />

    <WelcomeLayout
        :title="$t('welcome.title')"
        step="persona"
    >
        <div class="grid gap-2 sm:grid-cols-2">
            <WelcomeChoiceCard
                v-for="persona in personas"
                :key="persona"
                :label="$t(`welcome.personas.${persona}`)"
                :art="welcomeOptionArt(personaArt, persona)"
                :selected="form.persona === persona"
                :testid="`welcome-persona-${persona}`"
                @select="select(persona)"
            />
        </div>

        <template #actions>
            <WelcomeContinueButton
                :processing="form.processing"
                :disabled="!form.persona || form.processing"
                testid="welcome-persona-continue"
                @continue="submit"
            />
            <InputError :message="form.errors.persona" />
        </template>
    </WelcomeLayout>
</template>
