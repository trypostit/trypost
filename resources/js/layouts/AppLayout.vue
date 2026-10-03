<script setup lang="ts">
import { IconLayoutGrid } from '@tabler/icons-vue';
import type { Component } from 'vue';

import Breadcrumbs from '@/components/Breadcrumbs.vue';
import HeaderTitle from '@/components/HeaderTitle.vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    fullWidth?: boolean;
    title?: string;
    total?: number | null;
    icon?: Component;
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    fullWidth: false,
    title: undefined,
    total: undefined,
    icon: undefined,
    breadcrumbs: undefined,
});
</script>

<template>
    <AppLayout :full-width="fullWidth">
        <template
            v-if="$slots['header'] || title || breadcrumbs?.length"
            #header
        >
            <slot name="header">
                <HeaderTitle
                    v-if="breadcrumbs?.length"
                    :icon="icon ?? IconLayoutGrid"
                >
                    <Breadcrumbs :breadcrumbs="breadcrumbs" />
                </HeaderTitle>
                <HeaderTitle
                    v-else-if="title"
                    :title="title"
                    :total="total"
                    :icon="icon ?? IconLayoutGrid"
                />
            </slot>
        </template>
        <template v-if="$slots['header-actions']" #header-actions>
            <slot name="header-actions" />
        </template>
        <slot />
    </AppLayout>
</template>
