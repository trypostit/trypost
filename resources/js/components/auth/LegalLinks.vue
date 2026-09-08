<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import type { SharedData } from '@/types';

const page = usePage<SharedData>();

const sentence = computed(() =>
    trans('auth.legal', {
        terms_url: page.props.legal.terms,
        privacy_url: page.props.legal.privacy,
    }),
);
</script>

<template>
    <!-- eslint-disable-next-line vue/no-v-html -->
    <div
        data-testid="legal-links"
        class="text-center text-xs text-muted-foreground [&_a]:underline [&_a]:underline-offset-4 [&_a]:hover:text-primary"
        v-html="sentence"
    />
</template>
