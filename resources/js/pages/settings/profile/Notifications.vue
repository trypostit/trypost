<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import SettingsTabsNav from '@/components/settings/SettingsTabsNav.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/AppLayout.vue';
import { edit as editAuthentication } from '@/routes/app/authentication';
import { preferences as preferencesRoute } from '@/routes/app/notifications';
import { edit as editProfile } from '@/routes/app/profile';

interface Preferences {
    post_published: boolean;
    post_failed: boolean;
    account_disconnected: boolean;
}

interface Props {
    preferences: Preferences;
}

const props = defineProps<Props>();

const postPublished = ref(props.preferences.post_published);
const postFailed = ref(props.preferences.post_failed);
const accountDisconnected = ref(props.preferences.account_disconnected);
const processing = ref(false);

const tabs = computed(() => [
    {
        name: 'profile',
        label: trans('settings.nav.profile'),
        href: editProfile().url,
    },
    {
        name: 'authentication',
        label: trans('settings.nav.authentication'),
        href: editAuthentication().url,
    },
    {
        name: 'notifications',
        label: trans('settings.nav.notifications'),
        href: preferencesRoute().url,
    },
]);

const submit = () => {
    processing.value = true;

    router.put(
        preferencesRoute().url,
        {
            post_published: postPublished.value,
            post_failed: postFailed.value,
            account_disconnected: accountDisconnected.value,
        },
        {
            preserveScroll: true,
            onFinish: () => {
                processing.value = false;
            },
        },
    );
};
</script>

<template>
    <Head :title="$t('settings.notifications.title')" />

    <AppLayout :title="$t('settings.hub.title')">
        <div class="mx-auto max-w-4xl space-y-8 px-6 py-8">
            <p class="text-sm text-muted-foreground">
                {{ $t('settings.hub.description') }}
            </p>

            <SettingsTabsNav :tabs="tabs" active="notifications" />

            <section class="space-y-12">
                <div class="flex flex-col space-y-6">
                    <HeadingSmall
                        :title="$t('settings.notifications.heading')"
                        :description="$t('settings.notifications.description')"
                    />

                    <div class="space-y-3">
                        <div
                            class="flex items-center justify-between gap-4 rounded-xl border border-border bg-card p-4 shadow-2xs"
                        >
                            <div class="space-y-0.5">
                                <Label
                                    for="post_published"
                                    class="text-sm font-bold"
                                    >{{
                                        $t(
                                            'settings.notifications.post_published',
                                        )
                                    }}</Label
                                >
                                <p class="text-sm text-foreground/70">
                                    {{
                                        $t(
                                            'settings.notifications.post_published_description',
                                        )
                                    }}
                                </p>
                            </div>
                            <Switch
                                id="post_published"
                                v-model="postPublished"
                            />
                        </div>

                        <div
                            class="flex items-center justify-between gap-4 rounded-xl border border-border bg-card p-4 shadow-2xs"
                        >
                            <div class="space-y-0.5">
                                <Label
                                    for="post_failed"
                                    class="text-sm font-bold"
                                    >{{
                                        $t('settings.notifications.post_failed')
                                    }}</Label
                                >
                                <p class="text-sm text-foreground/70">
                                    {{
                                        $t(
                                            'settings.notifications.post_failed_description',
                                        )
                                    }}
                                </p>
                            </div>
                            <Switch id="post_failed" v-model="postFailed" />
                        </div>

                        <div
                            class="flex items-center justify-between gap-4 rounded-xl border border-border bg-card p-4 shadow-2xs"
                        >
                            <div class="space-y-0.5">
                                <Label
                                    for="account_disconnected"
                                    class="text-sm font-bold"
                                    >{{
                                        $t(
                                            'settings.notifications.account_disconnected',
                                        )
                                    }}</Label
                                >
                                <p class="text-sm text-foreground/70">
                                    {{
                                        $t(
                                            'settings.notifications.account_disconnected_description',
                                        )
                                    }}
                                </p>
                            </div>
                            <Switch
                                id="account_disconnected"
                                v-model="accountDisconnected"
                            />
                        </div>
                    </div>

                    <Button
                        :disabled="processing"
                        class="self-start"
                        @click="submit"
                    >
                        {{ $t('settings.notifications.save') }}
                    </Button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
