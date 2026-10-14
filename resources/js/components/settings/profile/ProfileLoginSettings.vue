<script setup lang="ts">
import { Form, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

import ConnectedAccountController from '@/actions/App/Http/Controllers/App/Settings/ConnectedAccountController';
import PasswordController from '@/actions/App/Http/Controllers/App/Settings/PasswordController';
import PasswordRequirements from '@/components/auth/PasswordRequirements.vue';
import type { PasswordRequirement } from '@/components/auth/PasswordRequirements.vue';
import SettingsField from '@/components/settings/SettingsField.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { connect } from '@/routes/app/profile/connected-accounts';

export type SocialProvider = 'google' | 'github';

export type ConnectedAccount = {
    provider: SocialProvider;
    label: string;
    connected: boolean;
    can_disconnect: boolean;
};

defineProps<{
    hasPassword: boolean;
    passwordRequirements: PasswordRequirement[];
    connectedAccounts: ConnectedAccount[];
}>();

const newPassword = ref('');

const clearNewPassword = (): void => {
    newPassword.value = '';
};

const page = usePage();
const providerEnabled = (provider: SocialProvider): boolean =>
    Boolean(
        page.props[
            provider === 'google' ? 'googleAuthEnabled' : 'githubAuthEnabled'
        ],
    );
</script>

<template>
    <div class="flex flex-col gap-10">
        <SettingsSection
            :title="
                hasPassword
                    ? $t('settings.profile.password.update_title')
                    : $t('settings.profile.password.set_title')
            "
            :description="
                hasPassword
                    ? $t('settings.profile.password.update_description')
                    : $t('settings.profile.password.set_description')
            "
        >
            <Form
                v-bind="PasswordController.update.form()"
                :options="{ preserveScroll: true }"
                reset-on-success
                :reset-on-error="[
                    'password',
                    'password_confirmation',
                    'current_password',
                ]"
                class="flex flex-col gap-6"
                v-slot="{ errors, processing }"
                @success="clearNewPassword"
                @error="clearNewPassword"
            >
                <SettingsField
                    v-if="hasPassword"
                    :label="
                        $t('settings.profile.password.current_password')
                    "
                    for="current_password"
                    :error="errors.current_password"
                >
                    <Input
                        id="current_password"
                        name="current_password"
                        type="password"
                        autocomplete="current-password"
                    />
                </SettingsField>

                <SettingsField
                    :label="$t('settings.profile.password.new_password')"
                    for="password"
                    :error="errors.password"
                >
                    <Input
                        id="password"
                        v-model="newPassword"
                        name="password"
                        type="password"
                        autocomplete="new-password"
                        data-testid="profile-new-password"
                    />
                    <PasswordRequirements
                        :password="newPassword"
                        :requirements="passwordRequirements"
                    />
                </SettingsField>

                <SettingsField
                    :label="
                        $t('settings.profile.password.confirm_password')
                    "
                    for="password_confirmation"
                    :error="errors.password_confirmation"
                >
                    <Input
                        id="password_confirmation"
                        name="password_confirmation"
                        type="password"
                        autocomplete="new-password"
                    />
                </SettingsField>

                <Button
                    :disabled="processing"
                    class="self-start"
                    data-test="update-password-button"
                >
                    {{
                        hasPassword
                            ? $t('settings.profile.password.save')
                            : $t('settings.profile.password.set')
                    }}
                </Button>
            </Form>
        </SettingsSection>

        <Separator />

        <SettingsSection
            :title="$t('settings.profile.providers.title')"
            :description="$t('settings.profile.providers.description')"
        >
            <ul class="flex flex-col gap-2">
                <SettingsListRow
                    v-for="account in connectedAccounts"
                    :key="account.provider"
                    :data-test="`connected-account-${account.provider}`"
                >
                    <template #media>
                        <span
                            class="inline-flex size-10 shrink-0 items-center justify-center rounded-lg border border-border bg-card"
                        >
                            <img
                                :src="`/images/social/${account.provider}.svg`"
                                :alt="account.label"
                                class="size-5"
                            />
                        </span>
                    </template>
                    <p
                        class="truncate text-sm leading-tight font-emphasis text-foreground"
                    >
                        {{ account.label }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            account.connected
                                ? $t(
                                      'settings.profile.providers.connected',
                                  )
                                : $t(
                                      'settings.profile.providers.not_connected',
                                  )
                        }}
                    </p>
                    <template #actions>
                        <Form
                            v-if="account.connected && account.can_disconnect"
                            v-bind="
                                ConnectedAccountController.destroy.form(
                                    account.provider,
                                )
                            "
                            :options="{ preserveScroll: true }"
                            #default="{ processing }"
                        >
                            <Button
                                type="submit"
                                variant="outline"
                                :disabled="processing"
                                :data-test="`disconnect-${account.provider}`"
                            >
                                {{
                                    $t(
                                        'settings.profile.providers.disconnect',
                                    )
                                }}
                            </Button>
                        </Form>
                        <Button
                            v-else-if="
                                !account.connected &&
                                providerEnabled(account.provider)
                            "
                            variant="outline"
                            as="a"
                            :href="connect(account.provider).url"
                            :data-test="`connect-${account.provider}`"
                        >
                            {{
                                $t('settings.profile.providers.connect')
                            }}
                        </Button>
                    </template>
                </SettingsListRow>
            </ul>
        </SettingsSection>
    </div>
</template>
