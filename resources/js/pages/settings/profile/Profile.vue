<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';

import ProfileController from '@/actions/App/Http/Controllers/App/Settings/ProfileController';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import PhotoUpload from '@/components/PhotoUpload.vue';
import SettingsTabsNav from '@/components/settings/SettingsTabsNav.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import AppLayout from '@/layouts/AppLayout.vue';
import { edit as editAuthentication } from '@/routes/app/authentication';
import { preferences as notificationPreferences } from '@/routes/app/notifications';
import {
    deletePhoto,
    edit as editProfile,
    uploadPhoto,
} from '@/routes/app/profile';
import { send } from '@/routes/verification';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
}

defineProps<Props>();

const page = usePage();
const user = computed(() => page.props.auth.user);

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
        href: notificationPreferences().url,
    },
]);
</script>

<template>
    <Head :title="$t('settings.profile.title')" />

    <AppLayout :title="$t('settings.hub.title')">
        <div class="mx-auto max-w-4xl space-y-8 px-6 py-8">
            <p class="text-sm text-muted-foreground">
                {{ $t('settings.hub.description') }}
            </p>

            <SettingsTabsNav :tabs="tabs" active="profile" />

            <section class="space-y-12">
                <div class="flex flex-col space-y-6">
                    <HeadingSmall
                        :title="$t('settings.profile.photo_heading')"
                        :description="$t('settings.profile.photo_description')"
                    />

                    <PhotoUpload
                        :photo-url="user.photo_url"
                        :has-photo="user.has_photo"
                        :name="user.name"
                        :upload-url="uploadPhoto().url"
                        :delete-url="deletePhoto().url"
                    />
                </div>

                <Separator />

                <div class="flex flex-col space-y-6">
                    <HeadingSmall
                        :title="$t('settings.profile.heading')"
                        :description="$t('settings.profile.description')"
                    />

                    <Form
                        v-bind="ProfileController.update.form()"
                        class="space-y-6"
                        v-slot="{ errors, processing }"
                    >
                        <div class="grid gap-2">
                            <Label for="name">{{
                                $t('settings.profile.name')
                            }}</Label>
                            <Input
                                id="name"
                                name="name"
                                :default-value="user.name"
                                autocomplete="name"
                                :placeholder="
                                    trans('settings.profile.name_placeholder')
                                "
                            />
                            <InputError :message="errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="email">{{
                                $t('settings.profile.email')
                            }}</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                :default-value="user.email"
                                autocomplete="username"
                                :placeholder="
                                    trans('settings.profile.email_placeholder')
                                "
                            />
                            <InputError :message="errors.email" />
                        </div>

                        <div v-if="mustVerifyEmail && !user.email_verified_at">
                            <p class="-mt-4 text-sm text-foreground/70">
                                {{ $t('settings.profile.email_unverified') }}
                                <Link
                                    :href="send()"
                                    as="button"
                                    class="font-semibold text-foreground underline decoration-foreground/30 underline-offset-4 transition-colors hover:decoration-foreground"
                                >
                                    {{
                                        $t(
                                            'settings.profile.resend_verification',
                                        )
                                    }}
                                </Link>
                            </p>

                            <div
                                v-if="status === 'verification-link-sent'"
                                class="mt-2 text-sm font-semibold text-emerald-700"
                            >
                                {{ $t('settings.profile.verification_sent') }}
                            </div>
                        </div>

                        <Button
                            :disabled="processing"
                            data-test="update-profile-button"
                        >
                            {{ $t('settings.profile.save') }}
                        </Button>
                    </Form>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
