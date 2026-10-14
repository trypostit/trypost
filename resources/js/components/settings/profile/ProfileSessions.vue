<script setup lang="ts">
import { computed, useTemplateRef } from 'vue';

import SessionController from '@/actions/App/Http/Controllers/App/Settings/SessionController';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    browserIconUrl,
    isMonochromeOsIcon,
    osIconUrl,
    parseBrowserName,
    parseOsName,
} from '@/lib/userAgent';

export type Session = {
    id: string;
    user_agent: string | null;
    last_active: string;
    is_current: boolean;
};

const props = defineProps<{
    sessions: Session[];
}>();

const orderedSessions = computed<Session[]>(() => [
    ...props.sessions.filter((session) => session.is_current),
    ...props.sessions.filter((session) => !session.is_current),
]);

const logoutDialog = useTemplateRef('logoutDialog');

const openLogoutDialog = (): void => {
    logoutDialog.value?.open({ url: SessionController.destroyOthers.url() });
};
</script>

<template>
    <SettingsSection
        data-testid="profile-sessions"
        :title="$t('settings.profile.sessions.title')"
        :description="$t('settings.profile.sessions.description')"
    >
        <template #actions>
            <Button
                variant="outline"
                data-test="log-out-other-sessions-button"
                :disabled="sessions.length <= 1"
                @click="openLogoutDialog"
            >
                {{ $t('settings.profile.sessions.log_out_others') }}
            </Button>
        </template>

        <ul class="flex flex-col gap-2">
            <SettingsListRow
                v-for="session in orderedSessions"
                :key="session.id"
                data-test="session-row"
            >
                <template #media>
                    <img
                        :src="browserIconUrl(session.user_agent)"
                        :alt="parseBrowserName(session.user_agent)"
                        class="size-9 shrink-0 object-contain"
                        :data-testid="`session-browser-icon-${session.id}`"
                    />
                </template>
                <p
                    class="flex min-w-0 items-center gap-1.5 text-sm leading-tight text-foreground"
                >
                    <span class="truncate font-emphasis">
                        {{ parseBrowserName(session.user_agent) }}
                    </span>
                    <template v-if="parseOsName(session.user_agent)">
                        <span class="text-muted-foreground">
                            {{ $t('settings.profile.sessions.on') }}
                        </span>
                        <img
                            :src="osIconUrl(session.user_agent)"
                            alt=""
                            class="size-4 shrink-0 object-contain"
                            :class="{
                                'dark:invert': isMonochromeOsIcon(
                                    session.user_agent,
                                ),
                            }"
                            :data-testid="`session-os-icon-${session.id}`"
                        />
                        <span class="truncate text-muted-foreground">
                            {{ parseOsName(session.user_agent) }}
                        </span>
                    </template>
                </p>
                <p
                    v-if="!session.is_current"
                    class="-mt-0.5 text-xs leading-4 text-muted-foreground"
                    :data-testid="`session-last-activity-${session.id}`"
                >
                    {{
                        $t('settings.profile.sessions.last_activity', {
                            time: session.last_active,
                        })
                    }}
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

        <ConfirmDeleteModal
            ref="logoutDialog"
            :title="$t('settings.profile.sessions.modal_title')"
            :description="$t('settings.profile.sessions.modal_description')"
            :action="$t('settings.profile.sessions.submit')"
            :cancel="$t('settings.profile.sessions.cancel')"
            action-test-id="confirm-log-out-other-sessions"
        />
    </SettingsSection>
</template>
