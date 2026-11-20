<script setup lang="ts">
import { IconFlameFilled, IconShare } from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { openPostComposer } from '@/composables/useGlobalPostComposer';
import { filled } from '@/lib/helpers';
import { streakShareFile } from '@/lib/streakShareImage';
import { formatFullNumber } from '@/lib/utils';
import { storeChunked } from '@/routes/app/media';
import type { PostingStreak } from '@/types/posting-streak';
import { uploadChunked } from '@/utils/chunkedUpload';

const props = defineProps<{
    streak: PostingStreak;
}>();

const open = ref(false);
const sharing = ref(false);

const weeks = (): string => formatFullNumber(props.streak.weeks);

/** What keeps the streak going: next week once this one has a post, this week otherwise. */
const nudgeKey = (): string => {
    if (props.streak.posted_this_week) {
        return 'analytics.posting_streak.keep_going';
    }

    return 'analytics.posting_streak.post_this_week';
};

const hasRank = (): boolean => filled(props.streak.top_percent);

const percent = (): string => String(props.streak.top_percent);

const schedulePost = (): void => {
    open.value = false;
    openPostComposer();
};

/** Opens the composer with the streak's image and a post that announces it. */
const sharePost = async (): Promise<void> => {
    sharing.value = true;

    try {
        const file = await streakShareFile(props.streak.weeks, {
            weeks: weeks(),
            unit: transChoice(
                'analytics.posting_streak.image_weeks',
                props.streak.weeks,
            ),
            tagline: trans('analytics.posting_streak.image_tagline'),
        });
        const media = await uploadChunked({ file, url: storeChunked.url() });

        open.value = false;
        openPostComposer({
            draft: {
                content: trans('analytics.posting_streak.share_caption', {
                    count: weeks(),
                }),
                media: [media],
                scheduled_at: null,
                label_ids: [],
            },
        });
    } catch {
        toast.error(trans('analytics.share.post_failed'));
    } finally {
        sharing.value = false;
    }
};
</script>

<template>
    <Popover v-model:open="open">
        <PopoverTrigger as-child>
            <button
                type="button"
                class="relative inline-flex size-8 shrink-0 items-center justify-center rounded-lg outline-hidden hover:bg-sidebar-accent focus-visible:ring-2 focus-visible:ring-sidebar-ring"
                :aria-label="$t('analytics.posting_streak.button')"
                data-testid="posting-streak-badge"
            >
                <IconFlameFilled
                    class="size-6 text-orange-500"
                    aria-hidden="true"
                />
                <span
                    class="absolute top-4 left-4 inline-flex h-4 min-w-5 items-center justify-center rounded-full bg-primary px-1 text-[11px] leading-4 font-[650] text-primary-foreground tabular-nums"
                    data-testid="posting-streak-count"
                    >{{ weeks() }}</span
                >
            </button>
        </PopoverTrigger>
        <PopoverContent
            side="bottom"
            align="center"
            :side-offset="16"
            class="w-61 rounded-xl p-2"
            data-testid="posting-streak-popover"
        >
            <div class="flex flex-col gap-4">
                <div class="relative h-25.25">
                    <div
                        class="h-16.5 rounded-lg bg-violet-50 bg-[radial-gradient(var(--color-primary)_1.5px,transparent_1.5px)] bg-size-[12px_12px] dark:bg-violet-950/40"
                    />
                    <div
                        class="absolute top-4 left-17.25 size-20 rounded-full bg-primary text-center font-heading text-[40px] leading-20 font-normal text-primary-foreground tabular-nums"
                    >
                        {{ weeks() }}
                    </div>
                    <div
                        class="absolute top-15 left-29.75 inline-flex size-10 items-center justify-center rounded-full bg-orange-100 ring-2 ring-popover dark:bg-orange-950"
                    >
                        <IconFlameFilled
                            class="streak-sway size-6 text-orange-500"
                            aria-hidden="true"
                        />
                    </div>
                </div>

                <div class="flex flex-col items-center text-center">
                    <h3
                        class="font-heading text-lg leading-[22.5px] font-medium text-foreground"
                        data-testid="posting-streak-title"
                    >
                        {{
                            $tChoice(
                                'analytics.posting_streak.title',
                                streak.weeks,
                                { count: weeks() },
                            )
                        }}
                    </h3>
                    <span
                        v-if="hasRank()"
                        class="mt-0.5 rounded bg-primary-text/20 px-1 text-xs leading-4.5 font-medium text-primary-text"
                        data-testid="posting-streak-rank"
                    >
                        {{
                            $t('analytics.posting_streak.top', {
                                percent: percent(),
                            })
                        }}
                    </span>
                    <p
                        class="mt-2 px-2 text-sm leading-5.25 text-muted-foreground"
                        data-testid="posting-streak-nudge"
                    >
                        {{ $t(nudgeKey()) }}
                    </p>
                </div>

                <div class="flex justify-center gap-2 px-2">
                    <Button
                        size="sm"
                        class="h-8 rounded-lg px-3"
                        data-testid="posting-streak-schedule"
                        @click="schedulePost"
                    >
                        {{ $t('analytics.posting_streak.schedule') }}
                    </Button>
                    <Button
                        variant="outline"
                        size="icon"
                        class="size-8 rounded-lg"
                        :loading="sharing"
                        :aria-label="$t('analytics.posting_streak.share')"
                        data-testid="posting-streak-share"
                        @click="sharePost"
                    >
                        <IconShare aria-hidden="true" />
                    </Button>
                </div>
            </div>
        </PopoverContent>
    </Popover>
</template>
