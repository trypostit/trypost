<script setup lang="ts">
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import { IconLoader2 } from '@tabler/icons-vue';
import { computed, onMounted, ref, watch } from 'vue';

import { calendar } from '@/routes/app';
import type { SharedData } from '@/types';

const props = defineProps<{
    subscriptionActive: boolean;
}>();

const page = usePage<SharedData>();

// Poll `auth` so `auth.plan` is set after the webhook writes plan_id.
const { stop } = usePoll(2000, {
    only: ['subscriptionActive', 'auth'],
});

const finishing = ref(false);

const purchaseReady = computed(
    (): boolean => props.subscriptionActive && page.props.auth.plan !== null,
);

const goNext = (): void => {
    router.visit(calendar.url());
};

// Card-required trials are already `subscribed()` (`trialing`) on first paint.
const completePurchase = (): void => {
    if (finishing.value) {
        return;
    }
    finishing.value = true;
    stop();
    goNext();
};

watch(purchaseReady, (ready) => {
    if (ready) {
        completePurchase();
    }
});

onMounted(() => {
    if (purchaseReady.value) {
        completePurchase();
    }
});
</script>

<template>
    <Head :title="$t('billing.processing.page_title')" />

    <section
        class="flex min-h-svh flex-col items-center bg-muted px-4 pt-13 pb-8 sm:justify-center sm:px-8"
    >
        <div
            class="flex w-full max-w-[360px] flex-col items-center gap-6 text-center"
            data-testid="billing-processing"
        >
            <img
                src="/images/trypost/icon.png"
                alt="TryPost"
                class="motion-auth-logo h-11 w-auto"
            />
            <div class="flex flex-col gap-4">
                <h1
                    class="font-heading text-xl leading-tight font-medium text-balance text-foreground"
                >
                    {{ $t('billing.processing.title') }}
                </h1>
                <p class="text-base text-balance text-foreground">
                    {{ $t('billing.processing.description') }}
                </p>
            </div>
            <IconLoader2
                class="size-6 animate-spin text-primary-strong motion-reduce:animate-none"
                aria-hidden="true"
            />
        </div>
    </section>
</template>
