<script setup lang="ts">
import { InfiniteScroll, Link } from '@inertiajs/vue3';
import { IconEdit, IconX } from '@tabler/icons-vue';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    getPlatformLabel,
    getPlatformLogo,
} from '@/composables/usePlatformLogo';
import { isImage } from '@/lib/mediaType';
import { edit as editPost } from '@/routes/app/posts';
import type { UndatedDraft } from '@/types/publish';

defineProps<{
    drafts: UndatedDraft[] | null;
}>();

const emit = defineEmits<{
    close: [];
}>();

const thumbnail = (draft: UndatedDraft): string | null =>
    (draft.media ?? []).find(isImage)?.url ?? null;

const target = (draft: UndatedDraft) => draft.post_platforms[0] ?? null;
</script>

<template>
    <aside
        class="flex min-h-0 w-full shrink-0 flex-col rounded-t-xl bg-muted md:w-[360px]"
        data-testid="calendar-undated-panel"
    >
        <div class="flex shrink-0 items-start gap-4 px-4 pt-4 pb-3">
            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <h2
                    class="text-sm leading-[17.5px] font-emphasis text-foreground"
                >
                    {{ $t('calendar.undated.title') }}
                </h2>
                <p class="text-xs leading-[18px] text-muted-foreground">
                    {{ $t('calendar.undated.description') }}
                </p>
            </div>
            <Button
                variant="ghost"
                size="icon"
                class="-me-2 -mt-2 shrink-0"
                :aria-label="$t('calendar.undated.close')"
                data-testid="calendar-undated-close"
                @click="emit('close')"
            >
                <IconX class="size-4" />
            </Button>
        </div>

        <div
            class="min-h-0 flex-1 overflow-y-auto overscroll-contain px-4 pb-4"
        >
            <div
                v-if="drafts === null"
                class="flex flex-col gap-2"
                aria-hidden="true"
            >
                <div
                    v-for="index in 3"
                    :key="index"
                    class="h-[59px] animate-pulse rounded-lg bg-secondary"
                />
            </div>
            <p
                v-else-if="drafts.length === 0"
                class="py-6 text-center text-sm text-muted-foreground"
                data-testid="calendar-undated-empty"
            >
                {{ $t('calendar.undated.empty') }}
            </p>
            <InfiniteScroll
                v-else
                data="undatedDrafts"
                items-element="#calendar-undated-list"
                preserve-url
            >
                <div id="calendar-undated-list" class="flex flex-col gap-2">
                    <Link
                        v-for="draft in drafts"
                        :key="draft.id"
                        :href="editPost.url(draft.id)"
                        class="flex flex-col gap-1 rounded-lg border border-dashed border-border-strong bg-card px-2 py-2 text-start transition-control hover:bg-secondary"
                        :data-testid="`calendar-undated-${draft.id}`"
                    >
                        <div class="flex min-w-0 items-center gap-2">
                            <img
                                v-if="target(draft)"
                                :src="getPlatformLogo(target(draft)!.platform)"
                                :alt="getPlatformLabel(target(draft)!.platform)"
                                class="size-4 shrink-0 rounded-sm"
                            />
                            <span
                                class="min-w-0 flex-1 truncate text-sm leading-[17.5px] font-emphasis text-foreground"
                            >
                                {{
                                    target(draft)?.social_account
                                        ?.display_label ??
                                    $t('calendar.undated.no_channel')
                                }}
                            </span>
                            <Badge variant="info" class="shrink-0">
                                <IconEdit />
                                {{ $t('calendar.undated.draft') }}
                            </Badge>
                        </div>
                        <div
                            v-if="draft.content?.trim() || thumbnail(draft)"
                            class="flex min-w-0 items-end gap-2"
                        >
                            <p
                                class="line-clamp-2 min-w-0 flex-1 text-sm leading-[17.5px] break-words text-foreground"
                            >
                                {{ draft.content?.trim() }}
                            </p>
                            <img
                                v-if="thumbnail(draft)"
                                :src="thumbnail(draft)!"
                                alt=""
                                loading="lazy"
                                class="size-8 shrink-0 rounded object-cover"
                            />
                        </div>
                    </Link>
                </div>

                <template #next="{ loading }">
                    <p
                        v-if="loading"
                        class="py-3 text-center text-sm text-muted-foreground"
                        role="status"
                    >
                        {{ $t('common.loading_more') }}
                    </p>
                </template>
            </InfiniteScroll>
        </div>
    </aside>
</template>
