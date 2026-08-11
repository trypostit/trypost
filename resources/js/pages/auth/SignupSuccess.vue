<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { IconLoader2 } from '@tabler/icons-vue';
import { onMounted } from 'vue';

import AuthBase from '@/layouts/AuthLayout.vue';
import { home } from '@/routes/app';

onMounted(() => {
    setTimeout(() => {
        router.visit(home.url());
    }, 5000);
});
</script>

<template>
    <Head :title="$t('auth.signup_success.page_title')" />

    <AuthBase>
        <div class="flex flex-col items-center gap-4 text-center">
            <IconLoader2 class="size-8 animate-spin text-muted-foreground" />
            <div>
                <h2 class="text-lg font-semibold tracking-tight">
                    {{ $t('auth.signup_success.title') }}
                </h2>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ $t('auth.signup_success.description') }}
                </p>
            </div>
        </div>
    </AuthBase>
</template>
