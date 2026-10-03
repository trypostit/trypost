<script setup lang="ts">
import { Form, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

import ProfileController from '@/actions/App/Http/Controllers/App/Settings/ProfileController';
import PhotoUpload from '@/components/PhotoUpload.vue';
import SettingsField from '@/components/settings/SettingsField.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { deletePhoto, uploadPhoto } from '@/routes/app/profile';
import { send } from '@/routes/verification';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
}

defineProps<Props>();

const page = usePage();
const user = computed(() => page.props.auth.user);
</script>

<template>
    <Head :title="$t('settings.profile.title')" />

    <SettingsLayout :title="$t('settings.profile.title')">
        <div class="flex flex-col gap-10">
            <SettingsSection
                :title="$t('settings.profile.photo_heading')"
                :description="$t('settings.profile.photo_description')"
            >
                <PhotoUpload
                    :photo-url="user.photo_url"
                    :has-photo="user.has_photo"
                    :name="user.name"
                    :upload-url="uploadPhoto().url"
                    :delete-url="deletePhoto().url"
                    size="sm"
                />
            </SettingsSection>

            <Separator />

            <SettingsSection
                :title="$t('settings.profile.heading')"
                :description="$t('settings.profile.description')"
            >
                <Form
                    v-bind="ProfileController.update.form()"
                    class="flex flex-col gap-6"
                    v-slot="{ errors, processing }"
                >
                    <SettingsField
                        :label="$t('settings.profile.name')"
                        for="name"
                        :error="errors.name"
                    >
                        <Input
                            id="name"
                            name="name"
                            :default-value="user.name"
                            autocomplete="name"
                            :placeholder="$t('settings.profile.name_placeholder')"
                        />
                    </SettingsField>

                    <SettingsField
                        :label="$t('settings.profile.email')"
                        for="email"
                        :error="errors.email"
                    >
                        <Input
                            id="email"
                            type="email"
                            name="email"
                            :default-value="user.email"
                            autocomplete="username"
                            :placeholder="$t('settings.profile.email_placeholder')"
                        />
                    </SettingsField>

                    <div
                        v-if="mustVerifyEmail && !user.email_verified_at"
                        class="-mt-4 flex flex-col gap-2"
                    >
                        <p class="text-sm text-muted-foreground">
                            {{ $t('settings.profile.email_unverified') }}
                            <Link
                                :href="send()"
                                as="button"
                                class="cursor-pointer text-primary-text underline underline-offset-4 transition-control hover:text-primary-text-hover"
                            >
                                {{ $t('settings.profile.resend_verification') }}
                            </Link>
                        </p>

                        <p
                            v-if="status === 'verification-link-sent'"
                            class="text-sm font-medium text-success-text"
                        >
                            {{ $t('settings.profile.verification_sent') }}
                        </p>
                    </div>

                    <Button
                        :disabled="processing"
                        class="self-start"
                        data-test="update-profile-button"
                    >
                        {{ $t('settings.profile.save') }}
                    </Button>
                </Form>
            </SettingsSection>
        </div>
    </SettingsLayout>
</template>
