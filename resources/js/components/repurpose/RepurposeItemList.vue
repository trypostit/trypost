<script setup lang="ts">
import { InfiniteScroll } from '@inertiajs/vue3';
import { IconExternalLink } from '@tabler/icons-vue';

import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import date from '@/date';
import { edit } from '@/routes/app/posts';
import type { RepurposeItem } from '@/types/repurpose';
import { repurposeItemStatusVariant } from '@/types/repurpose-status';

defineProps<{
    items: RepurposeItem[];
}>();
</script>

<template>
    <InfiniteScroll data="items" items-element="#repurpose-items-body" preserve-url>
        <Table data-testid="repurpose-items-table">
        <TableHeader>
            <TableRow>
                <TableHead class="whitespace-nowrap">{{ $t('repurposes.items.source') }}</TableHead>
                <TableHead class="whitespace-nowrap">{{ $t('repurposes.items.published_at') }}</TableHead>
                <TableHead class="whitespace-nowrap">{{ $t('repurposes.items.status') }}</TableHead>
                <TableHead class="w-full min-w-[16rem]">{{ $t('repurposes.items.detail') }}</TableHead>
                <TableHead class="whitespace-nowrap">{{ $t('repurposes.items.posts') }}</TableHead>
            </TableRow>
        </TableHeader>

        <TableBody id="repurpose-items-body">
            <TableRow v-for="item in items" :key="item.id" :data-testid="`repurpose-item-${item.id}`">
                <TableCell class="whitespace-nowrap">
                    <a
                        v-if="item.source_permalink"
                        :href="item.source_permalink"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 underline"
                    >
                        {{ $t('repurposes.items.view_original') }}
                        <IconExternalLink class="size-3.5" />
                    </a>
                    <span v-else class="text-muted-foreground">{{ item.source_media_id }}</span>
                </TableCell>

                <TableCell class="whitespace-nowrap">
                    {{ item.source_created_at ? date.formatDateTime(item.source_created_at) : '—' }}
                </TableCell>

                <TableCell class="whitespace-nowrap">
                    <Badge :variant="repurposeItemStatusVariant(item.status)">
                        {{ $t(`repurposes.items.statuses.${item.status}`) }}
                    </Badge>
                </TableCell>

                <TableCell class="w-full min-w-[16rem] text-sm text-muted-foreground">
                    <p
                        v-if="item.reason"
                        class="line-clamp-3 max-w-prose whitespace-normal"
                        :title="item.error ?? undefined"
                    >
                        {{ $t(`repurposes.items.reasons.${item.reason}`) }}
                    </p>
                    <p
                        v-else-if="item.error"
                        class="line-clamp-3 max-w-prose whitespace-normal break-words"
                        :title="item.error"
                    >
                        {{ item.error }}
                    </p>
                    <span v-else>—</span>
                </TableCell>

                <TableCell class="whitespace-nowrap">
                    <div class="flex flex-wrap gap-x-3 gap-y-1">
                        <a
                            v-for="post in item.posts ?? []"
                            :key="post.id"
                            :href="edit.url(post.id)"
                            class="text-sm underline"
                        >
                            {{ post.platform ? getPlatformLabel(post.platform) : $t('repurposes.items.open_post') }}
                        </a>
                        <span v-if="(item.posts ?? []).length === 0" class="text-muted-foreground">—</span>
                    </div>
                </TableCell>
            </TableRow>
        </TableBody>
        </Table>
    </InfiniteScroll>
</template>
