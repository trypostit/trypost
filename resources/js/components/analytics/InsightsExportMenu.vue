<script setup lang="ts">
import {
    IconChevronDown,
    IconDownload,
    IconFileText,
    IconMarkdown,
} from '@tabler/icons-vue';
import { computed } from 'vue';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { download } from '@/routes/app/insights';

const props = defineProps<{
    query: Record<string, string | string[]>;
}>();

const formats = computed(() => [
    {
        format: 'csv',
        label: 'analytics.insights.export.csv',
        icon: IconFileText,
        href: download.url('csv', { query: props.query }),
    },
    {
        format: 'md',
        label: 'analytics.insights.export.markdown',
        icon: IconMarkdown,
        href: download.url('md', { query: props.query }),
    },
]);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="outline"
                class="data-[state=open]:bg-accent"
                data-testid="insights-export"
            >
                <IconDownload aria-hidden="true" />
                {{ $t('analytics.insights.export.button') }}
                <IconChevronDown
                    class="text-muted-foreground"
                    aria-hidden="true"
                />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-40">
            <DropdownMenuItem
                v-for="item in formats"
                :key="item.format"
                as-child
            >
                <a
                    :href="item.href"
                    download
                    :data-testid="`insights-export-${item.format}`"
                >
                    <component :is="item.icon" aria-hidden="true" />
                    {{ $t(item.label) }}
                </a>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
