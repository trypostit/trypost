<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import WelcomeChoicePill from '@/components/welcome/WelcomeChoicePill.vue';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { referralSourceMeta, welcomeOptionMeta } from '@/lib/welcomeOptions';
import { store } from '@/routes/app/welcome/referral-source';
import type { WelcomeSummary } from '@/types';

const props = defineProps<{
    sources: string[];
    selected?: string | null;
    welcome: WelcomeSummary;
}>();

const form = useForm<{ referral_source: string }>({
    referral_source: props.selected ?? '',
});

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
    >
        <div class="flex flex-wrap gap-2.5">
            <WelcomeChoicePill
                v-for="source in sources"
                :key="source"
                :label="sourceLabel(source)"
                :meta="welcomeOptionMeta(referralSourceMeta, source)"
                :selected="isSelected(source)"
                :testid="`welcome-source-${source}`"
                @select="select(source)"
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
                    :disabled="form.referral_source === '' || form.processing"
                    data-testid="welcome-referral-continue"
                    @click="submit"
                >
                    {{ $t('welcome.continue') }}
                </Button>
                <InputError :message="form.errors.referral_source" />
            </div>
        </template>
    </WelcomeLayout>
</template>
