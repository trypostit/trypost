<script setup lang="ts">
import { computed, ref, shallowRef } from 'vue';

import AdminEmptyState from '@/components/admin/shared/AdminEmptyState.vue';
import AdminStatGrid from '@/components/admin/shared/AdminStatGrid.vue';
import AdminChangelogEntryDialog from '@/components/admin/users/profile/AdminChangelogEntryDialog.vue';
import SessionDevice from '@/components/admin/users/profile/SessionDevice.vue';
import AnalyticsSection from '@/components/analytics/workspace/AnalyticsSection.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import date from '@/date';
import { CHANGELOG_REACTIONS } from '@/lib/admin/changelog';
import { filled } from '@/lib/helpers';
import { browserIconUrl } from '@/lib/userAgent';
import { formatFullNumber } from '@/lib/utils';
import type { AdminStat } from '@/types/admin/shared';
import type { AdminActivity, AdminChangelogView, AdminUserActivityData } from '@/types/admin/users';
import type { ChangelogEntry } from '@/types/changelog';

const props = defineProps<{
    data: AdminUserActivityData;
    userName: string;
}>();

const ACTIVITY_LABELS: Record<string, string> = {
    'user.blocked': 'Blocked',
    'user.unblocked': 'Unblocked',
    'impersonation.started': 'Viewed the app as this user',
    'impersonation.stopped': 'Stopped viewing as this user',
    'post.retried': 'Retried a post',
};

const hasHistory = computed(() => filled(props.data.history));
const hasSessions = computed(() => filled(props.data.sessions));

const changelogStats = computed((): AdminStat[] => [
    { key: 'changelog-opened', label: 'Updates opened', value: formatFullNumber(props.data.changelog.opened) },
    { key: 'changelog-liked', label: 'Liked', value: formatFullNumber(props.data.changelog.liked) },
    { key: 'changelog-disliked', label: 'Disliked', value: formatFullNumber(props.data.changelog.disliked) },
]);

const hasChangelogViews = computed(() => filled(props.data.changelog.entries));

/** Kept after the dialog closes, so the entry holds while it animates out. */
const openedEntry = shallowRef<ChangelogEntry | null>(null);
const entryOpen = ref(false);

const openEntry = (view: AdminChangelogView): void => {
    openedEntry.value = view.entry;
    entryOpen.value = true;
};

const reactionEmoji = (view: AdminChangelogView): string => {
    if (filled(view.entry.reaction)) {
        return CHANGELOG_REACTIONS[view.entry.reaction].emoji;
    }

    return '';
};

const reactionLabel = (view: AdminChangelogView): string => {
    if (filled(view.entry.reaction)) {
        return CHANGELOG_REACTIONS[view.entry.reaction].label;
    }

    return 'No reaction';
};

const viewedLabel = (view: AdminChangelogView): string => {
    if (filled(view.viewed_at)) {
        return `Opened ${date.diffForHumans(view.viewed_at)}`;
    }

    return 'Opened';
};

const activityLabel = (activity: AdminActivity): string => {
    const label = ACTIVITY_LABELS[activity.event];

    if (filled(label)) {
        return label;
    }

    return activity.event;
};

const activityCauser = (activity: AdminActivity): string => {
    if (filled(activity.causer_name)) {
        return `by ${activity.causer_name}`;
    }

    return '';
};

const activityReason = (activity: AdminActivity): string => {
    const reason = activity.properties['reason'];

    if (filled(reason)) {
        return String(reason);
    }

    return '';
};

const sessionIp = (ip: string | null): string => {
    if (filled(ip)) {
        return ip;
    }

    return 'Unknown IP';
};

const sessionDetail = (session: AdminUserActivityData['sessions'][number]): string =>
    `${sessionIp(session.ip)} · Last active ${date.diffForHumans(session.last_activity)}`;
</script>

<template>
    <div class="flex flex-col gap-10" data-testid="admin-user-activity">
        <SettingsSection
            title="Admin history"
            description="What admins did to this user."
        >
            <AdminEmptyState v-if="!hasHistory">No admin has acted on this user yet.</AdminEmptyState>
            <ul
                v-else
                class="divide-y divide-border overflow-hidden rounded-xl border border-border bg-card"
                data-testid="admin-user-history"
            >
                <li
                    v-for="activity in data.history"
                    :key="activity.id"
                    class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 px-5 py-3.5 sm:px-6"
                >
                    <div class="min-w-0">
                        <p class="flex flex-wrap gap-x-1 text-sm text-foreground">
                            <span class="font-emphasis">{{ activityLabel(activity) }}</span>
                            <span class="text-muted-foreground">{{ activityCauser(activity) }}</span>
                        </p>
                        <p
                            v-if="activityReason(activity)"
                            class="mt-0.5 text-sm text-muted-foreground"
                        >
                            {{ activityReason(activity) }}
                        </p>
                    </div>
                    <span class="shrink-0 text-xs text-muted-foreground tabular-nums">{{
                        date.formatDateTime(activity.created_at)
                    }}</span>
                </li>
            </ul>
        </SettingsSection>

        <SettingsSection
            title="Sessions"
            description="Where the user is signed in now."
        >
            <AdminEmptyState v-if="!hasSessions">No open sessions.</AdminEmptyState>
            <ul v-else class="flex flex-col gap-2">
                <SettingsListRow
                    v-for="session in data.sessions"
                    :key="session.id"
                    :data-testid="`admin-user-session-${session.id}`"
                >
                    <template #media>
                        <img
                            :src="browserIconUrl(session.user_agent)"
                            alt=""
                            class="size-9 shrink-0 object-contain"
                        />
                    </template>
                    <SessionDevice :user-agent="session.user_agent" :show-browser-icon="false" />
                    <p class="-mt-0.5 text-xs leading-4 text-muted-foreground">
                        {{ sessionDetail(session) }}
                    </p>
                </SettingsListRow>
            </ul>
        </SettingsSection>

        <AnalyticsSection title="What's New" flush>
            <AdminStatGrid :stats="changelogStats" />
            <AdminEmptyState v-if="!hasChangelogViews" variant="section">No update opened yet.</AdminEmptyState>
            <ul v-else class="divide-y divide-border border-t border-border">
                <li v-for="view in data.changelog.entries" :key="view.entry.id">
                    <button
                        type="button"
                        class="flex w-full cursor-pointer items-center gap-3 px-4 py-3 text-start transition-colors hover:bg-muted/60 focus-visible:bg-muted/60 focus-visible:outline-none sm:px-5"
                        :data-testid="`admin-user-changelog-${view.entry.slug}`"
                        @click="openEntry(view)"
                    >
                        <span class="aspect-video w-20 shrink-0 overflow-hidden rounded-md bg-muted">
                            <img
                                v-if="view.entry.cover_url"
                                :src="view.entry.cover_url"
                                alt=""
                                class="size-full object-cover"
                            />
                        </span>
                        <span class="grid min-w-0 flex-1">
                            <span class="truncate text-sm font-emphasis text-foreground">{{
                                view.entry.title
                            }}</span>
                            <span class="truncate text-xs text-muted-foreground">{{
                                viewedLabel(view)
                            }}</span>
                        </span>
                        <span
                            class="flex shrink-0 items-center gap-1.5 text-xs text-muted-foreground"
                            :data-testid="`admin-user-changelog-reaction-${view.entry.slug}`"
                        >
                            <span class="text-base leading-none" aria-hidden="true">{{
                                reactionEmoji(view)
                            }}</span>
                            {{ reactionLabel(view) }}
                        </span>
                    </button>
                </li>
            </ul>
        </AnalyticsSection>

        <AdminChangelogEntryDialog
            v-model:open="entryOpen"
            :entry="openedEntry"
            :user-name="userName"
        />
    </div>
</template>
