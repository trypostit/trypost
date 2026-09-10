<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import WelcomeChoicePill from '@/components/welcome/WelcomeChoicePill.vue';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { personaMeta, welcomeOptionMeta } from '@/lib/welcomeOptions';
import { store } from '@/routes/app/welcome/persona';
import type { WelcomeSummary } from '@/types';

const props = defineProps<{
    personas: string[];
    selected?: string | null;
    welcome: WelcomeSummary;
}>();

const form = useForm({ persona: props.selected ?? '' });

const personaLabel = (value: string): string =>
    trans(`welcome.personas.${value}`);

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
        :description="$t('welcome.description')"
        step="persona"
    >
        <div class="flex flex-wrap gap-2.5">
            <WelcomeChoicePill
                v-for="persona in personas"
                :key="persona"
                :label="personaLabel(persona)"
                :meta="welcomeOptionMeta(personaMeta, persona)"
                :selected="form.persona === persona"
                :testid="`welcome-persona-${persona}`"
                @select="select(persona)"
            />
        </div>

        <template #actions>
            <div
                class="flex flex-col gap-2 sm:flex-row-reverse sm:items-center sm:gap-4"
            >
                <Button
                    type="button"
                    size="lg"
                    class="w-full sm:w-auto sm:min-w-48"
                    :disabled="!form.persona || form.processing"
                    data-testid="welcome-persona-continue"
                    @click="submit"
                >
                    {{ $t('welcome.continue') }}
                </Button>
                <InputError :message="form.errors.persona" />
            </div>
        </template>
    </WelcomeLayout>
</template>
