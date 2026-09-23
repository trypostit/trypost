<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import PublicationMetrics from '@/components/analytics/workspace/PublicationMetrics.vue';
import type { PublicationAnalyticsDetail } from '@/components/analytics/workspace/types';
import {
    getPlatformLabel,
    getPlatformLogo,
} from '@/composables/usePlatformLogo';
import dayjs from '@/dayjs';
import AppLayout from '@/layouts/AppLayout.vue';
import { analytics as analyticsRoute } from '@/routes/app';

const props = defineProps<{ detail: PublicationAnalyticsDetail }>();
const publication = computed(() => props.detail.publication);
const platformName = computed(() =>
    getPlatformLabel(publication.value.platform),
);
const origin = computed(() =>
    publication.value.origin === 'trypost'
        ? trans('analytics.detail.published_via_trypost')
        : trans('analytics.detail.published_on', {
              platform: platformName.value,
          }),
);
const thumbnail = computed(() => {
    const candidate = publication.value.preview_metadata?.thumbnail_url;
    return typeof candidate === 'string' && /^https:\/\//i.test(candidate)
        ? candidate
        : null;
});
const providerUrl = computed(() =>
    publication.value.permalink &&
    /^https:\/\//i.test(publication.value.permalink)
        ? publication.value.permalink
        : null,
);
</script>

<template>
    <AppLayout>
        <Head :title="`${platformName} analytics`" />
        <div
            class="mx-auto flex w-full max-w-5xl flex-col gap-5 px-4 py-7 sm:px-6"
        >
            <Link
                :href="analyticsRoute.url()"
                class="w-fit text-sm font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                >{{ $t('analytics.detail.back_to_analytics') }}</Link
            >

            <article
                class="rounded-xl border border-border bg-card p-5 shadow-xs sm:p-6"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-3 border-b border-border pb-4"
                >
                    <div class="flex items-center gap-3">
                        <img
                            :src="getPlatformLogo(publication.platform)"
                            :alt="platformName"
                            class="size-9 rounded-lg object-contain"
                        />
                        <div>
                            <h1 class="text-lg font-semibold text-foreground">
                                {{ origin }}
                            </h1>
                            <p class="text-sm text-muted-foreground">
                                {{
                                    publication.account_username
                                        ? `@${publication.account_username}`
                                        : publication.account_display_name ||
                                          platformName
                                }}
                            </p>
                        </div>
                    </div>
                    <span
                        class="rounded-full border border-border bg-muted/35 px-2.5 py-1 text-xs font-medium text-muted-foreground"
                        >{{ publication.content_type }}</span
                    >
                </div>

                <div class="flex flex-col gap-4 py-5 sm:flex-row">
                    <img
                        v-if="thumbnail"
                        :src="thumbnail"
                        alt=""
                        class="h-40 w-40 shrink-0 rounded-lg object-cover"
                    />
                    <div class="flex min-w-0 flex-1 flex-col gap-2">
                        <p
                            class="text-sm leading-6 break-words whitespace-pre-wrap text-foreground"
                        >
                            {{
                                publication.excerpt ||
                                $t('analytics.dashboard.no_excerpt')
                            }}
                        </p>
                        <p
                            v-if="publication.provider_published_at"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                dayjs(publication.provider_published_at).format(
                                    'LLL',
                                )
                            }}
                        </p>
                        <a
                            v-if="providerUrl"
                            :href="providerUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="w-fit text-sm font-medium text-primary underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                            >{{ $t('analytics.dashboard.view_post') }}</a
                        >
                    </div>
                </div>

                <div class="border-t border-border pt-5">
                    <PublicationMetrics :detail="detail" />
                </div>
            </article>
        </div>
    </AppLayout>
</template>
