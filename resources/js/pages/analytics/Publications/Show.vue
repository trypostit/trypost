<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { IconArrowLeft, IconArrowUpRight } from '@tabler/icons-vue';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import PublicationMetrics from '@/components/analytics/workspace/PublicationMetrics.vue';
import type { PublicationAnalyticsDetail } from '@/components/analytics/workspace/types';
import PageHeader from '@/components/PageHeader.vue';
import {
    getPlatformLabel,
    getPlatformLogo,
} from '@/composables/usePlatformLogo';
import date from '@/date';
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
        <Head
            :title="
                $t('analytics.detail.page_title', { platform: platformName })
            "
        />
        <div class="flex h-full flex-1 flex-col gap-6 px-6 py-8">
            <Link
                :href="analyticsRoute.url()"
                class="inline-flex w-fit items-center gap-2 text-sm font-semibold text-foreground/70 hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
            >
                <IconArrowLeft class="size-4" aria-hidden="true" />
                {{ $t('analytics.detail.back_to_analytics') }}
            </Link>

            <header
                class="flex flex-wrap items-center justify-between gap-4"
                data-testid="analytics-publication-header"
            >
                <div class="flex min-w-0 items-center gap-4">
                    <img
                        :src="getPlatformLogo(publication.platform)"
                        :alt="platformName"
                        class="size-12 shrink-0 rounded-xl border-2 border-foreground bg-card p-2 shadow-xs"
                    />
                    <PageHeader
                        :title="origin"
                        :description="
                            publication.account_username
                                ? `@${publication.account_username}`
                                : publication.account_display_name ||
                                  platformName
                        "
                    />
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <span
                        class="rounded-full border-2 border-foreground bg-violet-100 px-3 py-1 text-xs font-semibold text-foreground"
                        >{{
                            $t(
                                `analytics.detail.content_types.${publication.content_type}`,
                            )
                        }}</span
                    >
                    <a
                        v-if="providerUrl"
                        :href="providerUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex h-9 items-center gap-1.5 rounded-lg border-2 border-foreground bg-card px-3 text-sm font-semibold text-foreground shadow-xs hover:bg-violet-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                    >
                        {{ $t('analytics.dashboard.view_post') }}
                        <IconArrowUpRight class="size-4" aria-hidden="true" />
                    </a>
                </div>
            </header>

            <article
                class="rounded-xl border-2 border-foreground bg-card p-5 shadow-sm sm:p-6"
            >
                <div
                    class="grid gap-6"
                    :class="
                        thumbnail ? 'md:grid-cols-[14rem_minmax(0,1fr)]' : ''
                    "
                >
                    <div
                        v-if="thumbnail"
                        class="w-full max-w-56 overflow-hidden rounded-xl border-2 border-foreground bg-muted shadow-xs"
                    >
                        <img
                            :src="thumbnail"
                            alt=""
                            class="aspect-[4/5] h-full w-full object-cover"
                        />
                    </div>
                    <div class="flex min-w-0 flex-col justify-between gap-6">
                        <p
                            class="max-w-3xl text-base leading-7 break-words whitespace-pre-wrap text-foreground"
                            data-testid="analytics-publication-excerpt"
                        >
                            {{
                                publication.excerpt ||
                                $t('analytics.dashboard.no_excerpt')
                            }}
                        </p>
                        <p
                            v-if="publication.provider_published_at"
                            class="border-t border-foreground/15 pt-4 text-sm text-muted-foreground"
                        >
                            {{
                                date.formatDateTime(
                                    publication.provider_published_at,
                                )
                            }}
                        </p>
                    </div>
                </div>
            </article>
            <PublicationMetrics :detail="detail" />
        </div>
    </AppLayout>
</template>
