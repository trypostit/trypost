<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import {
    IconBriefcase,
    IconBuildingSkyscraper,
    IconBuildingStore,
    IconCheck,
    IconCode,
    IconDots,
    IconRocket,
    IconShoppingBag,
    IconSpeakerphone,
    IconUser,
} from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import type { FunctionalComponent } from 'vue';

import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import WelcomeLayout from '@/layouts/WelcomeLayout.vue';
import { store } from '@/routes/app/welcome/persona';

const props = defineProps<{
    personas: string[];
    selected?: string | null;
}>();

const form = useForm({ persona: props.selected ?? '' });

const personaMeta: Record<
    string,
    { icon: FunctionalComponent; iconClass: string; badge: string }
> = {
    creator: {
        icon: IconUser,
        iconClass: 'text-rose-700',
        badge: 'bg-rose-100',
    },
    freelancer: {
        icon: IconBriefcase,
        iconClass: 'text-amber-700',
        badge: 'bg-amber-100',
    },
    developer: {
        icon: IconCode,
        iconClass: 'text-cyan-700',
        badge: 'bg-cyan-100',
    },
    startup: {
        icon: IconRocket,
        iconClass: 'text-violet-700',
        badge: 'bg-violet-100',
    },
    agency: {
        icon: IconBuildingSkyscraper,
        iconClass: 'text-blue-700',
        badge: 'bg-blue-100',
    },
    small_business: {
        icon: IconBuildingStore,
        iconClass: 'text-emerald-700',
        badge: 'bg-emerald-100',
    },
    marketer: {
        icon: IconSpeakerphone,
        iconClass: 'text-fuchsia-700',
        badge: 'bg-fuchsia-100',
    },
    online_store: {
        icon: IconShoppingBag,
        iconClass: 'text-teal-700',
        badge: 'bg-teal-100',
    },
    other: {
        icon: IconDots,
        iconClass: 'text-foreground',
        badge: 'bg-muted',
    },
};

const metaFor = (value: string) =>
    personaMeta[value] ?? {
        icon: IconDots,
        iconClass: 'text-foreground',
        badge: 'bg-muted',
    };

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
        :step="1"
        size="4xl"
    >
        <div class="flex flex-wrap justify-center gap-2.5">
            <button
                v-for="persona in personas"
                :key="persona"
                type="button"
                :aria-pressed="form.persona === persona"
                :data-testid="`welcome-persona-${persona}`"
                :class="[
                    'inline-flex cursor-pointer items-center gap-3 rounded-full border-2 border-foreground py-2.5 ps-2.5 pe-5 text-start shadow-2xs transition-shadow hover:shadow-md',
                    form.persona === persona ? 'bg-violet-100' : 'bg-card',
                ]"
                @click="select(persona)"
            >
                <span
                    :class="[
                        'inline-flex size-11 shrink-0 items-center justify-center rounded-full border-2 border-foreground shadow-2xs',
                        metaFor(persona).badge,
                    ]"
                >
                    <component
                        :is="metaFor(persona).icon"
                        :class="[metaFor(persona).iconClass, 'size-6']"
                        stroke-width="2"
                    />
                </span>
                <span
                    class="text-sm font-bold tracking-tight text-foreground sm:text-base"
                >
                    {{ personaLabel(persona) }}
                </span>
                <span
                    v-if="form.persona === persona"
                    class="inline-flex size-5 shrink-0 items-center justify-center rounded-full border-2 border-foreground bg-foreground"
                >
                    <IconCheck
                        class="size-3 text-background"
                        stroke-width="3"
                    />
                </span>
            </button>
        </div>

        <div class="mx-auto flex w-full max-w-sm flex-col items-center gap-3">
            <InputError :message="form.errors.persona" />
            <Button
                type="button"
                size="lg"
                class="w-full rounded-full"
                :disabled="!form.persona || form.processing"
                data-testid="welcome-persona-continue"
                @click="submit"
            >
                {{ $t('welcome.continue') }}
            </Button>
        </div>
    </WelcomeLayout>
</template>
