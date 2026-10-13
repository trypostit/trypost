<script setup lang="ts">
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import { IconLoader2 } from '@tabler/icons-vue';
import { computed, onMounted, ref, watch } from 'vue';

import { useBelowBreakpoint } from '@/composables/useBreakpoint';
import AuthSplitLayout from '@/layouts/auth/AuthSplitLayout.vue';
import { index as postsIndex } from '@/routes/app/posts';
import { mcp } from '@/routes/app/welcome';
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

const isPhone = useBelowBreakpoint('sm');

/** Connecting an AI is a desktop task, so phones go straight to the schedule. */
const goNext = (): void => {
    router.visit(isPhone.value ? postsIndex.url() : mcp.url());
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
