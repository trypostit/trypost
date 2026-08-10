<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';

interface Tab {
    label: string;
    href: string;
    name: string;
}

defineProps<{
    tabs: Tab[];
    active: string;
}>();
</script>

<template>
    <Tabs :model-value="active">
        <TabsList>
            <TabsTrigger
                v-for="tab in tabs"
                :key="tab.name"
                :value="tab.name"
                as-child
            >
                <Link
                    :href="tab.href"
                    :data-testid="`settings-tab-${tab.name}`"
                >
                    {{ tab.label }}
                </Link>
            </TabsTrigger>
        </TabsList>
    </Tabs>
</template>
