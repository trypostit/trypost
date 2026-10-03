<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { IconDeviceDesktop, IconDeviceMobile } from '@tabler/icons-vue';
import { ref } from 'vue';

import AuthenticationController from '@/actions/App/Http/Controllers/App/Settings/AuthenticationController';
import DeleteUser from '@/components/DeleteUser.vue';
import InputError from '@/components/InputError.vue';
import SettingsField from '@/components/settings/SettingsField.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import SettingsLayout from '@/layouts/SettingsLayout.vue';
import { isMobileDevice, parseBrowserName, parseOsName } from '@/lib/userAgent';
import { connectProvider } from '@/routes/app/authentication';

type Session = {
    id: string;
    ip_address: string | null;
    user_agent: string | null;
    last_active: string;
    is_current: boolean;
};

type SocialProvider = 'google' | 'github';

type ConnectedAccount = {
    provider: SocialProvider;
    label: string;
    connected: boolean;
    can_disconnect: boolean;
};

defineProps<{
    sessions: Session[];
    hasPassword: boolean;
    connectedAccounts: ConnectedAccount[];
}>();

const logoutDialogOpen = ref(false);

const closeLogoutDialog = (): void => {
    logoutDialogOpen.value = false;
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
    <Head :title="$t('settings.authentication.page_title')" />

    <SettingsLayout :title="$t('settings.authentication.page_title')">
        <div class="flex flex-col gap-10">
            <SettingsSection
                :title="$t('settings.authentication.sessions.title')"
                :description="$t('settings.authentication.sessions.description')"
            >
                <ul class="flex flex-col gap-2">
                    <SettingsListRow
                        v-for="session in sessions"
                        :key="session.id"
                        :icon="
                            isMobileDevice(session.user_agent)
                                ? IconDeviceMobile
                                : IconDeviceDesktop
                        "
                        data-test="session-row"
                    >
                        <p
                            class="truncate text-sm leading-tight font-emphasis text-foreground"
                        >
                            {{ parseBrowserName(session.user_agent) }}
                            <span
                                v-if="parseOsName(session.user_agent)"
                                class="font-normal text-muted-foreground"
                            >
                                {{ $t('settings.authentication.sessions.on') }}
                                {{ parseOsName(session.user_agent) }}
                            </span>
                        </p>
                        <p
                            class="flex flex-wrap items-center gap-x-1.5 text-sm text-muted-foreground"
                        >
                            <span>{{
                                session.ip_address ??
                                $t('settings.authentication.sessions.unknown_ip')
                            }}</span>
                            <template v-if="!session.is_current">
                                <span aria-hidden="true">·</span>
                                <span>{{ session.last_active }}</span>
                            </template>
                        </p>
                        <template #actions>
                            <Badge
                                v-if="session.is_current"
                                variant="success"
                                class="shrink-0"
                            >
                                {{ $t('settings.authentication.sessions.active_now') }}
                            </Badge>
                        </template>
                    </SettingsListRow>
                </ul>

                <Dialog v-model:open="logoutDialogOpen">
                    <DialogTrigger as-child>
                        <Button
                            variant="outline"
                            class="self-start"
                            data-test="log-out-other-sessions-button"
                            :disabled="sessions.length <= 1"
                        >
                            {{
                                $t(
                                    'settings.authentication.sessions.log_out_others',
                                )
                            }}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <Form
                            v-bind="
                                AuthenticationController.destroyOtherSessions.form()
                            "
                            :options="{ preserveScroll: true }"
                            reset-on-success
                            @success="closeLogoutDialog"
                            class="flex flex-col gap-6"
                            v-slot="{ errors, processing }"
                        >
                            <DialogHeader>
                                <DialogTitle>{{
                                    $t(
                                        'settings.authentication.sessions.modal_title',
                                    )
                                }}</DialogTitle>
                                <DialogDescription>
                                    {{
                                        hasPassword
                                            ? $t(
                                                  'settings.authentication.sessions.modal_description_password',
                                              )
                                            : $t(
                                                  'settings.authentication.sessions.modal_description_email',
                                              )
                                    }}
                                </DialogDescription>
                            </DialogHeader>

                            <div v-if="hasPassword" class="flex flex-col gap-2">
                                <Label for="session_password" class="sr-only">
                                    {{
                                        $t(
                                            'settings.authentication.password.current_password',
                                        )
                                    }}
                                </Label>
                                <Input
                                    id="session_password"
                                    type="password"
                                    name="password"
                                    :placeholder="
                                        $t(
                                            'settings.authentication.sessions.password_placeholder',
                                        )
                                    "
                                />
                                <InputError :message="errors.password" />
                            </div>

                            <div v-else class="flex flex-col gap-2">
                                <Label
                                    for="session_email_confirmation"
                                    class="sr-only"
                                >
                                    {{ $t('settings.profile.email') }}
                                </Label>
                                <Input
                                    id="session_email_confirmation"
                                    type="email"
                                    name="email_confirmation"
                                    :placeholder="
                                        $t(
                                            'settings.authentication.sessions.email_placeholder',
                                        )
                                    "
                                    autocomplete="off"
                                />
                                <InputError
                                    :message="errors.email_confirmation"
                                />
                            </div>

                            <DialogFooter>
                                <DialogClose as-child>
                                    <Button variant="ghost">
                                        {{
                                            $t(
                                                'settings.authentication.sessions.cancel',
                                            )
                                        }}
                                    </Button>
                                </DialogClose>
                                <Button type="submit" :disabled="processing">
                                    {{
                                        $t(
                                            'settings.authentication.sessions.submit',
                                        )
                                    }}
                                </Button>
                            </DialogFooter>
                        </Form>
                    </DialogContent>
                </Dialog>
            </SettingsSection>

            <Separator />

            <SettingsSection
                :title="
                    hasPassword
                        ? $t('settings.authentication.password.update_title')
                        : $t('settings.authentication.password.set_title')
                "
                :description="
                    hasPassword
                        ? $t('settings.authentication.password.update_description')
                        : $t('settings.authentication.password.set_description')
                "
            >
                <Form
                    v-bind="AuthenticationController.updatePassword.form()"
                    :options="{ preserveScroll: true }"
                    reset-on-success
                    :reset-on-error="[
                        'password',
                        'password_confirmation',
                        'current_password',
                    ]"
                    class="flex flex-col gap-6"
                    v-slot="{ errors, processing }"
                >
                    <SettingsField
                        v-if="hasPassword"
                        :label="
                            $t('settings.authentication.password.current_password')
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
                        :label="$t('settings.authentication.password.new_password')"
                        for="password"
                        :error="errors.password"
                    >
                        <Input
                            id="password"
                            name="password"
                            type="password"
                            autocomplete="new-password"
                        />
                    </SettingsField>

                    <SettingsField
                        :label="
                            $t('settings.authentication.password.confirm_password')
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
                                ? $t('settings.authentication.password.save')
                                : $t('settings.authentication.password.set')
                        }}
                    </Button>
                </Form>
            </SettingsSection>

            <Separator />

            <SettingsSection
                :title="$t('settings.authentication.providers.title')"
                :description="$t('settings.authentication.providers.description')"
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
                                          'settings.authentication.providers.connected',
                                      )
                                    : $t(
                                          'settings.authentication.providers.not_connected',
                                      )
                            }}
                        </p>
                        <template #actions>
                            <Form
                                v-if="account.connected && account.can_disconnect"
                                v-bind="
                                    AuthenticationController.disconnectProvider.form(
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
                                            'settings.authentication.providers.disconnect',
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
                                :href="connectProvider(account.provider).url"
                                :data-test="`connect-${account.provider}`"
                            >
                                {{
                                    $t('settings.authentication.providers.connect')
                                }}
                            </Button>
                        </template>
                    </SettingsListRow>
                </ul>
            </SettingsSection>

            <Separator />

            <DeleteUser :has-password="hasPassword" />
        </div>
    </SettingsLayout>
</template>
