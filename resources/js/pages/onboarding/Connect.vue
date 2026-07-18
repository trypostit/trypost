<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { IconArrowRight } from '@tabler/icons-vue';
import { computed } from 'vue';

import { checkout } from '@/actions/App/Http/Controllers/App/OnboardingController';
import NetworkConnectGrid, {
    type AvailablePlatform,
    type ConnectedAccount,
} from '@/components/accounts/NetworkConnectGrid.vue';
import { Button } from '@/components/ui/button';
import { useTracking } from '@/composables/useTracking';

const props = defineProps<{
    platforms: AvailablePlatform[];
    accounts: ConnectedAccount[];
    plan: { name: string; interval: string };
}>();

const form = useForm({});

const { trackBeginCheckout } = useTracking();

const hasConnected = computed((): boolean => props.accounts.length > 0);

const submit = (): void => {
    if (!hasConnected.value || form.processing) {
        return;
    }

    trackBeginCheckout({ name: props.plan.name, interval: props.plan.interval });

    form.post(checkout.url());
};
</script>

<template>
    <Head :title="$t('onboarding.connect.title')" />

    <section class="relative min-h-screen overflow-hidden bg-background">
        <div
            class="pointer-events-none absolute inset-0 opacity-[0.06]"
            style="background-image: radial-gradient(circle, #0a0a0a 1px, transparent 1px); background-size: 28px 28px;"
        />
        <div class="pointer-events-none absolute -top-20 right-0 size-[560px] rounded-full bg-violet-200/50 blur-3xl" />

        <div class="relative mx-auto flex min-h-screen max-w-7xl flex-col justify-center px-6 py-12">
            <div class="mx-auto mb-10 max-w-xl space-y-3 text-center">
                <h1
                    class="text-balance text-3xl font-normal leading-[1.1] tracking-tight text-foreground sm:text-4xl"
                    style="font-family: var(--font-display);"
                >
                    {{ $t('onboarding.connect.title') }}
                </h1>
                <p class="text-balance text-base text-muted-foreground">
                    {{ $t('onboarding.connect.description') }}
                </p>
            </div>

            <NetworkConnectGrid
                :platforms="platforms"
                :connected-accounts="accounts"
                grid-class="grid-cols-3 sm:grid-cols-4 lg:grid-cols-7"
            />

            <div class="mx-auto mt-10 flex w-full max-w-sm flex-col items-center gap-3">
                <Button
                    type="button"
                    size="lg"
                    class="w-full rounded-full"
                    :disabled="!hasConnected || form.processing"
                    @click="submit"
                >
                    {{ $t('onboarding.continue') }}
                    <IconArrowRight class="size-4" />
                </Button>
                <p v-if="!hasConnected" class="text-center text-xs text-foreground/60">
                    {{ $t('onboarding.connect.must_connect') }}
                </p>
            </div>
        </div>
    </section>
</template>
