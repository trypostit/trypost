<script setup lang="ts">
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import { IconLoader2 } from '@tabler/icons-vue';
import { computed, onMounted, ref, watch } from 'vue';

import AuthSplitLayout from '@/layouts/auth/AuthSplitLayout.vue';
import type { SharedData } from '@/types';

const props = defineProps<{
    subscriptionActive: boolean;
    nextUrl: string;
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
    router.visit(props.nextUrl);
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

    <AuthSplitLayout
        :title="$t('billing.processing.title')"
        :description="$t('billing.processing.description')"
    >
        <div class="flex justify-center" data-testid="billing-processing">
            <IconLoader2
                class="size-6 animate-spin text-primary-strong motion-reduce:animate-none"
                aria-hidden="true"
            />
        </div>
    </AuthSplitLayout>
</template>
