<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { IconDeviceDesktop, IconDeviceMobile } from '@tabler/icons-vue';
import { ref } from 'vue';

import SessionController from '@/actions/App/Http/Controllers/App/Settings/SessionController';
import InputError from '@/components/InputError.vue';
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
import { isMobileDevice, parseBrowserName, parseOsName } from '@/lib/userAgent';

export type Session = {
    id: string;
    ip_address: string | null;
    user_agent: string | null;
    last_active: string;
    is_current: boolean;
};

defineProps<{
    sessions: Session[];
    hasPassword: boolean;
}>();

const logoutDialogOpen = ref(false);

const closeLogoutDialog = (): void => {
    logoutDialogOpen.value = false;
};
</script>

<template>
    <SettingsSection
        data-testid="profile-sessions"
        :title="$t('settings.profile.sessions.title')"
        :description="$t('settings.profile.sessions.description')"
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
                        {{ $t('settings.profile.sessions.on') }}
                        {{ parseOsName(session.user_agent) }}
                    </span>
                </p>
                <p
                    class="flex flex-wrap items-center gap-x-1.5 text-sm text-muted-foreground"
                >
                    <span>{{
                        session.ip_address ??
                        $t('settings.profile.sessions.unknown_ip')
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
                        {{ $t('settings.profile.sessions.active_now') }}
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
                            'settings.profile.sessions.log_out_others',
                        )
                    }}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    v-bind="
                        SessionController.destroyOthers.form()
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
                                'settings.profile.sessions.modal_title',
                            )
                        }}</DialogTitle>
                        <DialogDescription>
                            {{
                                hasPassword
                                    ? $t(
                                          'settings.profile.sessions.modal_description_password',
                                      )
                                    : $t(
                                          'settings.profile.sessions.modal_description_email',
                                      )
                            }}
                        </DialogDescription>
                    </DialogHeader>

                    <div v-if="hasPassword" class="flex flex-col gap-2">
                        <Label for="session_password" class="sr-only">
                            {{
                                $t(
                                    'settings.profile.password.current_password',
                                )
                            }}
                        </Label>
                        <Input
                            id="session_password"
                            type="password"
                            name="password"
                            :placeholder="
                                $t(
                                    'settings.profile.sessions.password_placeholder',
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
                                    'settings.profile.sessions.email_placeholder',
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
                                        'settings.profile.sessions.cancel',
                                    )
                                }}
                            </Button>
                        </DialogClose>
                        <Button type="submit" :disabled="processing">
                            {{
                                $t(
                                    'settings.profile.sessions.submit',
                                )
                            }}
                        </Button>
                    </DialogFooter>
                </Form>
            </DialogContent>
        </Dialog>
    </SettingsSection>
</template>
