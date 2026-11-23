<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

import date from '@/date';
import AuthLayout from '@/layouts/AuthLayout.vue';

const props = defineProps<{
    confirmationCode: string;
    completed: boolean;
    requestedAt: string;
    completedAt: string | null;
}>();

const day = computed(() =>
    date.formatDate(props.completed ? props.completedAt : props.requestedAt),
);
</script>

<template>
    <Head :title="$t('accounts.data_deletion.page_title')" />

    <AuthLayout
        :title="
            completed
                ? $t('accounts.data_deletion.completed_title')
                : $t('accounts.data_deletion.pending_title')
        "
        :description="
            completed
                ? $t('accounts.data_deletion.completed_description', {
                      date: day,
                  })
                : $t('accounts.data_deletion.pending_description', {
                      date: day,
                  })
        "
    >
        <div
            class="rounded-lg border p-4 text-center"
            data-testid="data-deletion-status"
        >
            <p class="text-sm text-muted-foreground">
                {{ $t('accounts.data_deletion.confirmation_code') }}
            </p>
            <p
                class="mt-1 font-mono text-sm break-all"
                data-testid="data-deletion-code"
            >
                {{ confirmationCode }}
            </p>
        </div>
    </AuthLayout>
</template>
