<script setup lang="ts">
import {
    IconChevronDown,
    IconDownload,
    IconFileText,
    IconFileTypePdf,
    IconMarkdown,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import InsightsPdfDialog from '@/components/analytics/InsightsPdfDialog.vue';
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
    pdfQuery?: Record<string, string | string[]>;
    pdfSections: readonly string[];
    readPdfColumns: () => readonly string[];
    reportLogoUrl: string | null;
}>();

const pdfOpen = ref(false);

const openPdfDialog = (): void => {
    pdfOpen.value = true;
};

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
    <div class="contents">
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
                    data-testid="insights-export-pdf"
                    @select="openPdfDialog"
                >
                    <IconFileTypePdf aria-hidden="true" />
                    {{ $t('analytics.insights.export.pdf.menu') }}
                </DropdownMenuItem>
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
        <InsightsPdfDialog
            v-model:open="pdfOpen"
            :query="pdfQuery ?? query"
            :sections="pdfSections"
            :read-columns="readPdfColumns"
            :channel-id="channelId"
            :logo-url="reportLogoUrl"
        />
    </div>
</template>
