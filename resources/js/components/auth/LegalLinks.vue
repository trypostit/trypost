<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

import type { SharedData } from '@/types';

const page = usePage<SharedData>();
</script>

<template>
    <!-- eslint-disable vue/no-v-html -->
    <div
        data-testid="legal-links"
        class="text-center text-sm text-balance text-muted-foreground [&_a]:text-primary-text [&_a]:underline [&_a]:underline-offset-4 [&_a]:transition-control [&_a]:hover:text-primary-text-hover"
        v-html="
            $t('auth.legal', {
                terms_url: page.props.legal.terms,
                privacy_url: page.props.legal.privacy,
            })
        "
    />
    <!-- eslint-enable vue/no-v-html -->
</template>
