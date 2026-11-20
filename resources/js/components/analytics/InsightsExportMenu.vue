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
import { download as channelDownload } from '@/routes/app/channels/insights';
import { download } from '@/routes/app/insights';

const props = defineProps<{
    query: Record<string, string | string[]>;
    channelId?: string;
}>();

const hrefFor = (format: string): string =>
    props.channelId
        ? channelDownload.url(
              { account: props.channelId, format },
              { query: props.query },
          )
        : download.url(format, { query: props.query });

const formats = computed(() => [
    {
        format: 'csv',
        label: 'analytics.insights.export.csv',
        icon: IconFileText,
        href: hrefFor('csv'),
    },
    {
        format: 'md',
        label: 'analytics.insights.export.markdown',
        icon: IconMarkdown,
        href: hrefFor('md'),
    },
]);
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="outline"
                class="data-[state=open]:bg-accent max-sm:size-8 max-sm:px-0"
                data-testid="insights-export"
            >
                <IconDownload aria-hidden="true" />
                <span class="max-sm:sr-only">{{
                    $t('analytics.insights.export.button')
                }}</span>
                <IconChevronDown
                    class="text-muted-foreground max-sm:hidden"
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
