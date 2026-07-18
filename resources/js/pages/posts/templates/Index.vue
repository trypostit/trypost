<script setup lang="ts">
import { Head, InfiniteScroll, router, useHttp } from '@inertiajs/vue3';
import { IconBookmarks, IconCheck, IconChevronDown, IconLoader2, IconSearch } from '@tabler/icons-vue';
import { trans, transChoice } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';

import { apply as applyRoute, index as templatesIndex } from '@/actions/App/Http/Controllers/App/PostTemplateController';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { getPlatformLogo } from '@/composables/usePlatformLogo';
import debounce from '@/debounce';
import AppLayout from '@/layouts/AppLayout.vue';
import { cn } from '@/lib/utils';

interface Slide {
    title: string;
    body: string;
    image_keywords: string[];
}

interface PostTemplate {
    slug: string;
    name: string;
    description: string | null;
    category: string;
    platform: string;
    content: string;
    slides: Slide[] | null;
    image_count: number;
    image_keywords: string[] | null;
}

interface ScrollTemplates {
    data: PostTemplate[];
}

interface Props {
    templates: ScrollTemplates;
    filters: {
        search: string;
        platform: string;
    };
    /** ISO date carried over from the calendar's per-day "+" button. */
    date?: string | null;
}

const props = withDefaults(defineProps<Props>(), {
    date: null,
});

const searchQuery = ref(props.filters.search);
const selectedPlatform = ref<string>(props.filters.platform || '');
const platformPickerOpen = ref(false);
const applyingSlug = ref<string | null>(null);

const PLATFORM_BRAND: Record<string, string> = {
    instagram_carousel: 'Instagram',
    instagram_feed: 'Instagram',
    instagram_story: 'Instagram',
    linkedin_post: 'LinkedIn',
    linkedin_page_post: 'LinkedIn Page',
    x_post: 'X',
    threads_post: 'Threads',
    bluesky_post: 'Bluesky',
    mastodon_post: 'Mastodon',
    facebook_post: 'Facebook',
    facebook_story: 'Facebook',
    pinterest_pin: 'Pinterest',
};

const FORMAT_LABEL: Record<string, string> = {
    instagram_carousel: 'Carousel',
    instagram_feed: 'Feed',
    instagram_story: 'Story',
    linkedin_post: 'Post',
    linkedin_page_post: 'Page post',
    x_post: 'Post',
    threads_post: 'Post',
    bluesky_post: 'Post',
    mastodon_post: 'Post',
    facebook_post: 'Post',
    facebook_story: 'Story',
    pinterest_pin: 'Pin',
};

const PLATFORM_LOGO_KEY: Record<string, string> = {
    instagram_carousel: 'instagram',
    instagram_feed: 'instagram',
    instagram_story: 'instagram',
    linkedin_post: 'linkedin',
    linkedin_page_post: 'linkedin-page',
    x_post: 'x',
    threads_post: 'threads',
    bluesky_post: 'bluesky',
    mastodon_post: 'mastodon',
    facebook_post: 'facebook',
    facebook_story: 'facebook',
    pinterest_pin: 'pinterest',
};

const platformBrand = (platform: string): string => PLATFORM_BRAND[platform] ?? platform;
const formatLabel = (platform: string): string => FORMAT_LABEL[platform] ?? '';
const platformLogo = (platform: string): string => getPlatformLogo(PLATFORM_LOGO_KEY[platform] ?? platform);

const platformOptions = computed(() =>
    Object.keys(PLATFORM_BRAND).map((value) => ({
        value,
        brand: PLATFORM_BRAND[value],
        format: FORMAT_LABEL[value],
        logo: platformLogo(value),
    })),
);

const selectedPlatformOption = computed(() =>
    platformOptions.value.find((p) => p.value === selectedPlatform.value),
);

const reload = (params: { search?: string; platform?: string }) => {
    router.get(
        templatesIndex.url(),
        {
            search: params.search || undefined,
            platform: params.platform || undefined,
            date: props.date || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
};

const search = debounce(() => {
    reload({ search: searchQuery.value, platform: selectedPlatform.value });
}, 300);

watch(searchQuery, () => search());

const onPlatformChange = (next: string) => {
    const value = next === 'all' ? '' : next;
    selectedPlatform.value = value;
    platformPickerOpen.value = false;
    reload({ search: searchQuery.value, platform: value });
};

const isCurrentPlatform = (value: string): boolean =>
    value === 'all' ? selectedPlatform.value === '' : selectedPlatform.value === value;

const hasActiveSearch = computed(() => Boolean(searchQuery.value?.trim()) || Boolean(selectedPlatform.value));

const applyTemplate = async (template: PostTemplate) => {
    if (applyingSlug.value) return;
    applyingSlug.value = template.slug;

    try {
        const http = useHttp<{ date: string | null }>({ date: props.date ?? null });
        const data = (await http.post(applyRoute.url(template.slug))) as {
            post_id: string;
            redirect_url: string;
        };
        router.visit(data.redirect_url);
    } finally {
        applyingSlug.value = null;
    }
};

const pageTitle = computed(() => trans('posts.templates.browser_title'));
</script>

<template>
    <Head :title="pageTitle" />

    <AppLayout>
        <div class="flex h-full flex-1 flex-col gap-6 px-6 py-8">
            <PageHeader :title="pageTitle" :description="$t('posts.templates.browser_description')" />

            <!-- Filters: platform + search -->
            <div class="flex flex-wrap items-center gap-3">
                <Popover v-model:open="platformPickerOpen">
                    <PopoverTrigger as-child>
                        <Button
                            type="button"
                            variant="outline"
                            role="combobox"
                            :aria-expanded="platformPickerOpen"
                            class="w-56 justify-between font-medium"
                        >
                            <span v-if="!selectedPlatformOption" class="text-foreground/60">
                                {{ $t('posts.templates.all_platforms') }}
                            </span>
                            <span v-else class="flex min-w-0 items-center gap-2">
                                <span class="inline-flex size-5 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card">
                                    <img
                                        :src="selectedPlatformOption.logo"
                                        :alt="selectedPlatformOption.brand"
                                        class="size-full object-cover"
                                    />
                                </span>
                                <span class="truncate font-semibold">{{ selectedPlatformOption.brand }}</span>
                                <span class="shrink-0 text-xs font-medium text-foreground/60">{{ selectedPlatformOption.format }}</span>
                            </span>
                            <IconChevronDown class="ml-2 size-4 shrink-0 text-foreground/60" />
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent class="w-(--reka-popover-trigger-width) p-0" align="start">
                        <Command>
                            <CommandInput :placeholder="$t('posts.templates.platform_search_placeholder')" />
                            <CommandList>
                                <CommandEmpty>{{ $t('posts.templates.no_platform_match') }}</CommandEmpty>
                                <CommandGroup>
                                    <CommandItem value="all" @select="onPlatformChange('all')">
                                        <span>{{ $t('posts.templates.all_platforms') }}</span>
                                        <IconCheck :class="cn('ml-auto size-4', isCurrentPlatform('all') ? 'opacity-100' : 'opacity-0')" stroke-width="3" />
                                    </CommandItem>
                                    <CommandItem
                                        v-for="opt in platformOptions"
                                        :key="opt.value"
                                        :value="`${opt.brand} ${opt.format} ${opt.value}`"
                                        @select="onPlatformChange(opt.value)"
                                    >
                                        <span class="inline-flex size-5 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card">
                                            <img :src="opt.logo" :alt="opt.brand" class="size-full object-cover" />
                                        </span>
                                        <span class="truncate font-semibold">{{ opt.brand }}</span>
                                        <span class="shrink-0 text-xs font-medium text-foreground/60">{{ opt.format }}</span>
                                        <IconCheck :class="cn('ml-auto size-4', isCurrentPlatform(opt.value) ? 'opacity-100' : 'opacity-0')" stroke-width="3" />
                                    </CommandItem>
                                </CommandGroup>
                            </CommandList>
                        </Command>
                    </PopoverContent>
                </Popover>

                <div class="relative w-full max-w-sm">
                    <IconSearch class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-foreground/60" />
                    <Input
                        v-model="searchQuery"
                        :placeholder="$t('posts.templates.search_placeholder')"
                        class="pl-9"
                    />
                </div>
            </div>

            <EmptyState
                v-if="templates.data.length === 0"
                :icon="IconBookmarks"
                :title="hasActiveSearch ? $t('posts.templates.no_search_results') : $t('posts.templates.no_templates')"
                :description="hasActiveSearch ? $t('posts.templates.try_different_search') : ''"
            />

            <InfiniteScroll v-else data="templates" items-element="#templates-grid" preserve-url>
                <!-- CSS columns masonry: cards keep their natural height + don't split mid-card -->
                <div id="templates-grid" class="columns-1 gap-4 sm:columns-2 lg:columns-3 xl:columns-4">
                    <article
                        v-for="template in templates.data"
                        :key="template.slug"
                        class="group mb-4 flex break-inside-avoid flex-col rounded-2xl border-2 border-foreground bg-card p-5 shadow-2xs transition-all hover:-translate-y-0.5 hover:shadow-md"
                    >
                        <header class="flex items-center gap-2">
                            <span class="inline-flex size-7 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-foreground bg-card shadow-2xs">
                                <img
                                    :src="platformLogo(template.platform)"
                                    :alt="platformBrand(template.platform)"
                                    class="size-full object-cover"
                                />
                            </span>
                            <span class="truncate text-sm font-bold text-foreground">{{ platformBrand(template.platform) }}</span>
                            <Badge variant="warning" class="ml-auto shrink-0 -rotate-2">
                                {{ formatLabel(template.platform) }}
                            </Badge>
                        </header>

                        <h3 class="mt-3 text-base font-bold leading-snug text-foreground">{{ template.name }}</h3>

                        <p
                            v-if="template.description"
                            class="mt-2 text-sm leading-relaxed text-foreground/70"
                        >
                            {{ template.description }}
                        </p>

                        <div class="mt-3 flex items-center gap-2">
                            <Badge variant="secondary" class="shrink-0">
                                {{ $t(`posts.templates.category.${template.category}`) }}
                            </Badge>
                            <span
                                v-if="template.slides && template.slides.length > 0"
                                class="text-xs font-medium text-foreground/60"
                            >
                                {{ transChoice('posts.templates.slides_count', template.slides.length, { count: template.slides.length }) }}
                            </span>
                        </div>

                        <Button
                            size="sm"
                            class="mt-4 w-full"
                            :disabled="applyingSlug === template.slug"
                            @click="applyTemplate(template)"
                        >
                            <IconLoader2 v-if="applyingSlug === template.slug" class="size-4 animate-spin" />
                            {{ applyingSlug === template.slug ? $t('posts.templates.applying') : $t('posts.templates.use_this') }}
                        </Button>
                    </article>
                </div>
            </InfiniteScroll>
        </div>
    </AppLayout>
</template>
