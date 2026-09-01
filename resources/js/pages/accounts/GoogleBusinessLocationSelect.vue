<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconBuildingStore } from '@tabler/icons-vue';

import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import PopupLayout from '@/layouts/PopupLayout.vue';
import { select as selectGoogleBusinessLocation } from '@/routes/app/social/google-business';

interface Location {
    id: string;
    account_name: string;
    location_name: string;
    title: string;
    address: string | null;
}

interface Workspace {
    id: string;
    name: string;
}

interface Props {
    workspace: Workspace;
    locations: Location[];
}

defineProps<Props>();

const form = useForm({ location_id: '' });

const handleSelectLocation = (location: Location) => {
    form.location_id = location.id;
    form.post(selectGoogleBusinessLocation.url());
};
</script>

<template>
    <PopupLayout :title="$t('accounts.google_business.title')">
        <div class="flex flex-col gap-6">
            <div class="flex items-center gap-3">
                <img src="/images/accounts/google_business.png" alt="Google Business Profile" class="h-10 w-10" />
                <div>
                    <h1 class="text-xl font-bold tracking-tight">{{ $t('accounts.google_business.title') }}</h1>
                    <p class="text-sm text-muted-foreground">{{ $t('accounts.google_business.description') }}</p>
                </div>
            </div>

            <div v-if="locations.length === 0" class="py-12 text-center">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-muted">
                    <IconBuildingStore class="h-7 w-7 text-muted-foreground" />
                </div>
                <h3 class="mt-4 text-lg font-semibold">{{ $t('accounts.google_business.no_locations') }}</h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{ $t('accounts.google_business.no_locations_description') }}
                </p>
            </div>

            <div v-else class="grid gap-3">
                <div
                    v-for="location in locations"
                    :key="location.id"
                    class="flex items-center gap-4 rounded-lg border bg-card p-4"
                    dusk="google-business-location"
                >
                    <Avatar class="h-12 w-12 shrink-0 rounded-lg">
                        <AvatarFallback class="rounded-lg bg-blue-100 dark:bg-blue-900">
                            <IconBuildingStore class="h-6 w-6 text-blue-600 dark:text-blue-400" />
                        </AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate font-semibold">{{ location.title }}</h3>
                        <p v-if="location.address" class="truncate text-sm text-muted-foreground">
                            {{ location.address }}
                        </p>
                    </div>
                    <div class="flex shrink-0 items-center gap-2">
                        <Button size="sm" dusk="choose-google-business-location" :disabled="form.processing" @click="handleSelectLocation(location)">
                            {{ $t('accounts.google_business.choose') }}
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    </PopupLayout>
</template>
