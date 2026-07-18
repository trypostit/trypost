<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconBrandInstagram, IconExternalLink } from '@tabler/icons-vue';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import PopupLayout from '@/layouts/PopupLayout.vue';
import { select as selectPage } from '@/routes/app/social/instagram-facebook';

interface Page {
    page_id: string;
    page_name: string;
    page_picture: string | null;
    ig_id: string;
    ig_username: string;
    ig_name: string | null;
    ig_picture: string | null;
}

interface Workspace {
    id: string;
    name: string;
}

interface Props {
    workspace: Workspace;
    pages: Page[];
}

defineProps<Props>();

const form = useForm({ page_id: '' });

const handleSelectPage = (page: Page) => {
    form.page_id = page.page_id;
    form.post(selectPage.url());
};

const openExternal = (url: string | null) => {
    if (url) {
        window.open(url, '_blank', 'noopener');
    }
};

const igUrl = (username: string): string => `https://www.instagram.com/${username}`;
</script>

<template>
    <PopupLayout :title="$t('accounts.instagram_facebook.title')">
        <div class="flex flex-col gap-6">
            <div class="flex items-center gap-3">
                <img src="/images/accounts/instagram.png" alt="Instagram" class="h-10 w-10" />
                <div>
                    <h1 class="text-xl font-bold tracking-tight">{{ $t('accounts.instagram_facebook.title') }}</h1>
                    <p class="text-sm text-muted-foreground">{{ $t('accounts.instagram_facebook.description') }}</p>
                </div>
            </div>

            <div v-if="pages.length === 0" class="py-12 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                    <IconBrandInstagram class="h-7 w-7 text-muted-foreground" />
                </div>
                <h3 class="mt-4 text-lg font-semibold">{{ $t('accounts.instagram_facebook.no_pages') }}</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ $t('accounts.instagram_facebook.no_pages_description') }}
                </p>
            </div>

            <div v-else class="grid gap-3">
                <div
                    v-for="page in pages"
                    :key="page.ig_id"
                    class="flex items-center gap-4 rounded-lg border bg-card p-4"
                    dusk="instagram-page"
                >
                    <Avatar class="h-12 w-12 shrink-0 rounded-lg">
                        <AvatarImage v-if="page.ig_picture" :src="page.ig_picture" class="object-cover" />
                        <AvatarFallback class="rounded-lg bg-pink-100 dark:bg-pink-900">
                            <IconBrandInstagram class="h-6 w-6 text-pink-600 dark:text-pink-400" />
                        </AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate font-semibold">@{{ page.ig_username }}</h3>
                        <p class="truncate text-sm text-muted-foreground">{{ page.page_name }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="openExternal(igUrl(page.ig_username))"
                        >
                            <IconExternalLink class="h-4 w-4" />
                            <span class="hidden sm:inline">{{ $t('accounts.instagram_facebook.view') }}</span>
                        </Button>
                        <Button size="sm" dusk="choose-instagram-page" :disabled="form.processing" @click="handleSelectPage(page)">
                            {{ $t('accounts.instagram_facebook.choose') }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </PopupLayout>
</template>
