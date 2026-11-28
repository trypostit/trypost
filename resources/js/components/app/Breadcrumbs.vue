<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

defineProps<{
    breadcrumbs: BreadcrumbItemType[];
}>();
</script>

<template>
    <Breadcrumb data-testid="breadcrumbs">
        <BreadcrumbList class="flex-nowrap">
            <template v-for="(item, index) in breadcrumbs" :key="index">
                <BreadcrumbItem class="min-w-0">
                    <BreadcrumbPage
                        v-if="index === breadcrumbs.length - 1"
                        class="truncate font-medium"
                    >
                        {{ item.title }}
                    </BreadcrumbPage>
                    <BreadcrumbLink v-else as-child>
                        <Link :href="item.href ?? '#'">{{ item.title }}</Link>
                    </BreadcrumbLink>
                </BreadcrumbItem>
                <BreadcrumbSeparator
                    v-if="index !== breadcrumbs.length - 1"
                    class="shrink-0"
                />
            </template>
        </BreadcrumbList>
    </Breadcrumb>
</template>
