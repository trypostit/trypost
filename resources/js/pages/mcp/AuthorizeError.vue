<script setup lang="ts">
import AuthorizeLayout from '@/layouts/mcp/AuthorizeLayout.vue';

defineProps<{
    error: string;
    errorDescription: string;
}>();
</script>

<template>
    <AuthorizeLayout
        :page-title="$t('mcp.authorize.error_page_title')"
        :title="$t('mcp.authorize.error_title')"
        :description="$t('mcp.authorize.error_body')"
    >
        <p
            class="text-sm text-muted-foreground"
            dusk="mcp-authorize-error-description"
        >
            {{ errorDescription }}
        </p>

        <p
            class="rounded-lg border-2 border-foreground bg-muted px-3 py-2 font-mono text-xs text-foreground"
            dusk="mcp-authorize-error-code"
        >
            {{ $t('mcp.authorize.error_code', { error }) }}
        </p>
    </AuthorizeLayout>
</template>
