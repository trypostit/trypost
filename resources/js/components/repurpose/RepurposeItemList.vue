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
                <TableHead>{{ $t('repurposes.items.source') }}</TableHead>
                <TableHead>{{ $t('repurposes.items.published_at') }}</TableHead>
                <TableHead>{{ $t('repurposes.items.status') }}</TableHead>
                <TableHead>{{ $t('repurposes.items.detail') }}</TableHead>
                <TableHead>{{ $t('repurposes.items.posts') }}</TableHead>
            </TableRow>
        </TableHeader>

        <TableBody id="repurpose-items-body">
            <TableRow v-for="item in items" :key="item.id" :data-testid="`repurpose-item-${item.id}`">
                <TableCell>
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

                <TableCell>
                    {{ item.source_created_at ? date.formatDateTime(item.source_created_at) : '—' }}
                </TableCell>

                <TableCell>
                    <Badge :variant="repurposeItemStatusVariant(item.status)">
                        {{ $t(`repurposes.items.statuses.${item.status}`) }}
                    </Badge>
                </TableCell>

                <TableCell class="text-sm text-muted-foreground">
                    <span v-if="item.reason">{{ $t(`repurposes.items.reasons.${item.reason}`) }}</span>
                    <span v-else-if="item.error">{{ item.error }}</span>
                    <span v-else>—</span>
                </TableCell>

                <TableCell>
                    <div class="flex flex-wrap gap-2">
                        <a
                            v-for="post in item.posts ?? []"
                            :key="post.id"
                            :href="edit.url(post.id)"
                            class="text-sm underline"
                        >
                            {{ $t('repurposes.items.open_post') }}
                        </a>
                        <span v-if="(item.posts ?? []).length === 0" class="text-muted-foreground">—</span>
                    </div>
                </TableCell>
            </TableRow>
        </TableBody>
        </Table>
    </InfiniteScroll>
</template>
