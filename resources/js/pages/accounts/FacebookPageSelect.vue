<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconBrandFacebook, IconExternalLink } from '@tabler/icons-vue';

import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import PopupLayout from '@/layouts/PopupLayout.vue';
import { select as selectFacebookPage } from '@/routes/app/social/facebook';

interface Page {
    id: string;
    name: string;
    username: string | null;
    picture: string | null;
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
    form.page_id = page.id;
    form.post(selectFacebookPage.url());
};

const openExternal = (url: string | null) => {
    if (url) {
        window.open(url, '_blank', 'noopener');
    }
};

const pageUrl = (username: string | null): string | null =>
    username ? `https://www.facebook.com/${username}` : null;
</script>

<template>
    <PopupLayout :title="$t('accounts.facebook.title')">
        <div class="flex flex-col gap-6">
            <div class="flex items-center gap-3">
                <img src="/images/accounts/facebook.png" alt="Facebook" class="h-10 w-10" />
                <div>
                    <h1 class="text-xl font-bold tracking-tight">{{ $t('accounts.facebook.title') }}</h1>
                    <p class="text-sm text-muted-foreground">{{ $t('accounts.facebook.description') }}</p>
                </div>
            </div>

            <div v-if="pages.length === 0" class="py-12 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                    <IconBrandFacebook class="h-7 w-7 text-muted-foreground" />
                </div>
                <h3 class="mt-4 text-lg font-semibold">{{ $t('accounts.facebook.no_pages') }}</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ $t('accounts.facebook.no_pages_description') }}
                </p>
            </div>

            <div v-else class="grid gap-3">
                <div
                    v-for="page in pages"
                    :key="page.id"
                    class="flex items-center gap-4 rounded-lg border bg-card p-4"
                    dusk="facebook-page"
                >
                    <Avatar class="h-12 w-12 shrink-0 rounded-lg">
                        <AvatarImage v-if="page.picture" :src="page.picture" class="object-cover" />
                        <AvatarFallback class="rounded-lg bg-blue-100 dark:bg-blue-900">
                            <IconBrandFacebook class="h-6 w-6 text-blue-600 dark:text-blue-400" />
                        </AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate font-semibold">{{ page.name }}</h3>
                        <p v-if="page.username" class="truncate text-sm text-muted-foreground">
                            facebook.com/{{ page.username }}
                        </p>
                        <p v-else class="truncate text-sm text-muted-foreground">{{ $t('accounts.facebook.page_label') }}</p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Button
                            v-if="pageUrl(page.username)"
                            variant="ghost"
                            size="sm"
                            @click="openExternal(pageUrl(page.username))"
                        >
                            <IconExternalLink class="h-4 w-4" />
                            <span class="hidden sm:inline">{{ $t('accounts.facebook.view') }}</span>
                        </Button>
                        <Button size="sm" dusk="choose-facebook-page" :disabled="form.processing" @click="handleSelectPage(page)">
                            {{ $t('accounts.facebook.choose') }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </PopupLayout>
</template>
