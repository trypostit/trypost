<script setup lang="ts">
import { Head } from '@inertiajs/vue3';

import { Button } from '@/components/ui/button';
import AuthBase from '@/layouts/AuthLayout.vue';

defineProps<{
    error: string;
    errorDescription: string;
}>();

const closeWindow = (): void => {
    window.close();
};
</script>

<template>
    <AuthBase
        :title="$t('mcp.authorize.error_title')"
        :description="$t('mcp.authorize.error_body')"
    >
        <Head :title="$t('mcp.authorize.error_page_title')" />

        <div class="flex flex-col gap-6">
            <p class="text-sm text-muted-foreground">
                {{ errorDescription }}
            </p>

            <p
                class="rounded-lg border-2 border-foreground bg-muted px-3 py-2 font-mono text-xs text-foreground"
                data-testid="mcp-authorize-error-code"
            >
                {{ $t('mcp.authorize.error_code', { error }) }}
            </p>

            <Button
                type="button"
                class="w-full"
                data-testid="mcp-authorize-error-close"
                @click="closeWindow"
            >
                {{ $t('mcp.authorize.error_close') }}
            </Button>
        </div>
    </AuthBase>
</template>
