<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { IconBuildingStore, IconMapPin } from '@tabler/icons-vue';

import { Button } from '@/components/ui/button';
import PopupLayout from '@/layouts/PopupLayout.vue';
import { select as selectGoogleBusinessProfileLocations } from '@/routes/app/social/google-business-profile';

interface Location {
    id: string;
    title: string;
    store_code: string | null;
    storefront_address: {
        addressLines?: string[];
        locality?: string;
        administrativeArea?: string;
    } | null;
    maps_uri: string | null;
    is_selected: boolean;
}

const props = defineProps<{ locations: Location[] }>();

const form = useForm({
    location_ids: props.locations.filter((location) => location.is_selected).map((location) => location.id),
});

const toggle = (locationId: string) => {
    form.location_ids = form.location_ids.includes(locationId)
        ? form.location_ids.filter((id) => id !== locationId)
        : [...form.location_ids, locationId];
};

const address = (location: Location): string =>
    [location.storefront_address?.addressLines?.join(', '), location.storefront_address?.locality, location.storefront_address?.administrativeArea]
        .filter(Boolean)
        .join(', ');
</script>

<template>
    <PopupLayout :title="$t('accounts.google_business_profile.title')">
        <div class="flex flex-col gap-6">
            <div class="flex items-center gap-3">
                <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-950">
                    <IconBuildingStore class="h-6 w-6 text-blue-600 dark:text-blue-400" />
                </div>
                <div>
                    <h1 class="text-xl font-bold tracking-tight">{{ $t('accounts.google_business_profile.title') }}</h1>
                    <p class="text-sm text-muted-foreground">{{ $t('accounts.google_business_profile.description') }}</p>
                </div>
            </div>

            <div v-if="locations.length === 0" class="py-12 text-center">
                <h3 class="text-lg font-semibold">{{ $t('accounts.google_business_profile.no_locations') }}</h3>
                <p class="text-sm text-muted-foreground">{{ $t('accounts.google_business_profile.no_locations_description') }}</p>
            </div>

            <div v-else class="grid gap-3">
                <button
                    v-for="location in locations"
                    :key="location.id"
                    type="button"
                    class="flex w-full items-center gap-4 rounded-lg border bg-card p-4 text-left transition-colors hover:bg-muted/50"
                    :class="{ 'border-blue-500 ring-2 ring-blue-500/20': form.location_ids.includes(location.id) }"
                    @click="toggle(location.id)"
                >
                    <input
                        type="checkbox"
                        class="h-4 w-4 rounded border-input"
                        :checked="form.location_ids.includes(location.id)"
                        tabindex="-1"
                    />
                    <div class="min-w-0 flex-1">
                        <h3 class="truncate font-semibold">{{ location.title }}</h3>
                        <p v-if="address(location)" class="flex items-center gap-1 truncate text-sm text-muted-foreground">
                            <IconMapPin class="h-4 w-4 shrink-0" />
                            {{ address(location) }}
                        </p>
                        <p v-if="location.store_code" class="text-xs text-muted-foreground">
                            {{ $t('accounts.google_business_profile.store_code', { code: location.store_code }) }}
                        </p>
                    </div>
                </button>
            </div>

            <Button
                v-if="locations.length > 0"
                :disabled="form.processing || form.location_ids.length === 0"
                @click="form.post(selectGoogleBusinessProfileLocations.url())"
            >
                {{ form.processing ? $t('accounts.google_business_profile.saving') : $t('accounts.google_business_profile.save') }}
            </Button>
        </div>
    </PopupLayout>
</template>
