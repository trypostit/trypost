<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import {
    IconAlertTriangle,
    IconArrowLeft,
    IconCheck,
    IconChevronDown,
    IconClockHour4,
    IconLock,
    IconMinus,
    IconPlugConnectedX,
    IconRefresh,
    IconSwitchHorizontal,
    type Icon,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import PlatformLogo from '@/components/PlatformLogo.vue';
import { Avatar } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { getPlatformLabel } from '@/composables/usePlatformLogo';
import ConnectLayout from '@/layouts/ConnectLayout.vue';
import { DOCS_URL, platformGuideDocsUrl } from '@/lib/docs';
import { cn } from '@/lib/utils';
import { finish } from '@/routes/app/social/connect';

type ConnectState =
    | 'select'
    | 'cancelled'
    | 'missing_permission'
    | 'expired'
    | 'error';

interface ConnectIdentity {
    key: string;
    platform: string;
    name: string | null;
    username: string | null;
    avatar: string | null;
    type: string;
    locked: boolean;
}

const props = defineProps<{
    platform: string;
    state: ConnectState;
    reason: string | null;
    reconnecting: boolean;
    identities: ConnectIdentity[];
    retryUrl: string | null;
    switchUrl: string | null;
    backUrl: string;
}>();

const FORM_STEP_PLATFORMS = ['bluesky', 'mastodon'];

const LOGIN_SITES: Record<string, string> = {
    'instagram-facebook': 'Facebook',
    youtube: 'Google',
    google_business: 'Google',
};

const STATE_ICONS: Record<Exclude<ConnectState, 'select'>, Icon> = {
    cancelled: IconPlugConnectedX,
    missing_permission: IconLock,
    expired: IconClockHour4,
    error: IconAlertTriangle,
};

const selectableKeys = computed(() =>
    props.identities
        .filter((identity) => !identity.locked)
        .map((identity) => identity.key),
);

const initialSelection = (): string[] =>
    props.reconnecting || props.identities.length === 1
        ? selectableKeys.value
        : [];

const form = useForm<{ identities: string[] }>({
    identities: initialSelection(),
});

const isSwitchingAccount = ref(false);

const isSelecting = computed(
    () => props.state === 'select' && !isSwitchingAccount.value,
);
const canSwitchAccount = computed(
    () => props.state === 'select' && props.switchUrl !== null,
);
const hasSeveral = computed(() => props.identities.length > 1);
const isAllConnected = computed(
    () => props.identities.length > 0 && selectableKeys.value.length === 0,
);
const selectedCount = computed(() => form.identities.length);

const title = computed(() =>
    hasSeveral.value
        ? 'accounts.connect.title_select'
        : 'accounts.connect.title_single',
);

const allSelected = computed<boolean | 'indeterminate'>(() => {
    if (selectedCount.value === 0) {
        return false;
    }

    return selectedCount.value === selectableKeys.value.length
        ? true
        : 'indeterminate';
});

const guideUrl = computed(
    () => platformGuideDocsUrl(props.platform) ?? DOCS_URL,
);

const stateIcon = computed(() =>
    props.state === 'select' ? undefined : STATE_ICONS[props.state],
);

const networkName = computed(() => getPlatformLabel(props.platform));

const loginSiteName = computed(
    () => LOGIN_SITES[props.platform] ?? networkName.value,
);

const isSelected = (identity: ConnectIdentity): boolean =>
    form.identities.includes(identity.key);

const toggleIdentity = (identity: ConnectIdentity): void => {
    if (identity.locked) {
        return;
    }

    form.identities = isSelected(identity)
        ? form.identities.filter((key) => key !== identity.key)
        : [...form.identities, identity.key];
};

const toggleAll = (): void => {
    form.identities =
        allSelected.value === true
            ? []
            : [...selectableKeys.value];
};

const subtitle = computed(() =>
    hasSeveral.value
        ? 'accounts.connect.subtitle_select'
        : 'accounts.connect.subtitle_single',
);

const pageTitle = computed(() => {
    if (isSwitchingAccount.value) {
        return 'accounts.connect.switch.title';
    }

    return isSelecting.value
        ? title.value
        : `accounts.connect.states.${props.state}.title`;
});

const openSwitchAccount = (): void => {
    if (FORM_STEP_PLATFORMS.includes(props.platform) && props.switchUrl) {
        router.visit(props.switchUrl);

        return;
    }

    isSwitchingAccount.value = true;
};

const closeSwitchAccount = (): void => {
    isSwitchingAccount.value = false;
};

const finishConnection = (): void => {
    form.post(finish.url(props.platform), { preserveScroll: true });
};

const identityCardClass = (identity: ConnectIdentity): string =>
    cn(
        'flex items-center gap-4 rounded-xl border bg-card p-4 transition-colors',
        identity.locked
            ? 'cursor-default'
            : 'cursor-pointer hover:bg-accent/40',
        isSelected(identity) && 'border-primary-strong ring-1 ring-primary-strong',
    );
</script>

<template>
    <ConnectLayout
        :title="$t(pageTitle)"
        :platform="platform"
        :close-url="backUrl"
    >
        <template v-if="isSwitchingAccount" #header-start>
            <Button
                variant="ghost"
                size="icon"
                :aria-label="$t('accounts.connect.actions.back')"
                data-testid="connect-switch-back"
                @click="closeSwitchAccount"
            >
                <IconArrowLeft class="size-4" />
            </Button>
        </template>
        <template v-else-if="canSwitchAccount" #header-start>
            <Button
                variant="ghost"
                class="-ml-2 min-w-0 text-muted-foreground"
                data-testid="connect-switch-account"
                @click="openSwitchAccount"
            >
                <IconSwitchHorizontal class="size-4 shrink-0" aria-hidden="true" />
                <span
                    class="whitespace-nowrap sm:hidden"
                    data-testid="connect-switch-account-short"
                >
                    {{ $t('accounts.connect.switch.button_short') }}
                </span>
                <span
                    class="hidden whitespace-nowrap sm:inline"
                    data-testid="connect-switch-account-label"
                >
                    {{ $t('accounts.connect.switch.button') }}
                </span>
            </Button>
        </template>

        <div
            v-if="isSwitchingAccount"
            class="flex flex-col items-center gap-4 py-6 text-center"
            data-testid="connect-switch-state"
        >
            <span
                class="flex size-12 items-center justify-center rounded-full bg-muted"
                aria-hidden="true"
            >
                <IconRefresh class="size-6 text-muted-foreground" />
            </span>
            <div class="flex flex-col gap-2">
                <h1
                    class="text-xl leading-tight font-medium text-foreground"
                    data-testid="connect-switch-title"
                >
                    {{ $t('accounts.connect.switch.title') }}
                </h1>
                <p
                    class="text-sm text-muted-foreground"
                    data-testid="connect-switch-description"
                >
                    {{ $t('accounts.connect.switch.description', { site: loginSiteName, network: networkName }) }}
                </p>
            </div>
        </div>

        <template v-else-if="isSelecting">
            <div class="flex flex-col items-center gap-2 text-center">
                <h1
                    class="text-2xl leading-tight font-semibold text-foreground"
                    data-testid="connect-title"
                >
                    {{ $t(title) }}
                </h1>
                <p
                    class="text-sm text-muted-foreground"
                    data-testid="connect-subtitle"
                >
                    {{ $t(subtitle, { network: networkName }) }}
                </p>
                <p
                    v-if="isAllConnected"
                    class="text-sm text-muted-foreground"
                    data-testid="connect-all-connected"
                >
                    {{ $t('accounts.connect.all_connected', { network: networkName }) }}
                </p>
            </div>

            <div class="flex flex-col gap-3">
                <div
                    v-if="hasSeveral"
                    class="flex items-center justify-between gap-3 text-sm"
                >
                    <span
                        class="text-muted-foreground"
                        data-testid="connect-selected-count"
                    >
                        {{ $t('accounts.connect.selected', { count: String(selectedCount) }) }}
                    </span>
                    <label
                        class="flex cursor-pointer items-center gap-2 font-medium"
                        for="connect-select-all"
                    >
                        {{ $t('accounts.connect.select_all') }}
                        <Checkbox
                            id="connect-select-all"
                            :model-value="allSelected"
                            :disabled="isAllConnected"
                            class="data-[state=indeterminate]:border-primary-strong data-[state=indeterminate]:bg-primary-strong data-[state=indeterminate]:text-primary-strong-foreground"
                            data-testid="connect-select-all"
                            @update:model-value="toggleAll"
                        >
                            <IconMinus
                                v-if="allSelected === 'indeterminate'"
                                class="size-3.5 text-current"
                            />
                            <IconCheck v-else class="size-3.5 text-current" />
                        </Checkbox>
                    </label>
                </div>

                <ul class="flex flex-col gap-2" data-testid="connect-identities">
                    <li v-for="identity in identities" :key="identity.key">
                        <component
                            :is="identity.locked ? 'div' : 'label'"
                            :for="identity.locked ? undefined : `connect-identity-${identity.key}`"
                            :class="identityCardClass(identity)"
                            :data-testid="`connect-identity-${identity.key}`"
                            :data-selected="isSelected(identity)"
                            :data-locked="identity.locked"
                        >
                            <Avatar
                                :src="identity.avatar"
                                :name="identity.name ?? ''"
                                class="size-12 shrink-0 rounded-full"
                                fallback-class="rounded-full"
                            />
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span
                                    :class="cn('font-semibold break-words', identity.locked ? 'text-muted-foreground' : 'text-foreground')"
                                >
                                    {{ identity.name }}
                                </span>
                                <span class="text-sm text-muted-foreground">
                                    {{ $t(`accounts.connect.types.${identity.type}`) }}
                                </span>
                            </span>
                            <span
                                v-if="identity.locked"
                                class="flex shrink-0 items-center gap-1 text-sm font-medium whitespace-nowrap text-muted-foreground"
                                :data-testid="`connect-identity-connected-${identity.key}`"
                            >
                                <IconCheck class="size-4 shrink-0" aria-hidden="true" />
                                {{ $t('accounts.connect.connected') }}
                            </span>
                            <Checkbox
                                v-else
                                :id="`connect-identity-${identity.key}`"
                                :model-value="isSelected(identity)"
                                :data-testid="`connect-identity-checkbox-${identity.key}`"
                                @update:model-value="toggleIdentity(identity)"
                            />
                        </component>
                    </li>
                </ul>
            </div>
        </template>

        <div
            v-else
            class="flex flex-col items-center gap-4 py-6 text-center"
            :data-testid="`connect-state-${state}`"
        >
            <span
                class="flex size-12 items-center justify-center rounded-full bg-muted"
                aria-hidden="true"
            >
                <component :is="stateIcon" class="size-6 text-muted-foreground" />
            </span>
            <div class="flex flex-col gap-2">
                <h1 class="text-xl leading-tight font-medium text-foreground">
                    {{ $t(`accounts.connect.states.${state}.title`) }}
                </h1>
                <p
                    class="text-sm text-muted-foreground"
                    data-testid="connect-state-description"
                >
                    {{
                        state === 'error'
                            ? reason
                            : $t(`accounts.connect.states.${state}.description`)
                    }}
                </p>
            </div>
            <div class="flex w-full flex-col-reverse gap-2 sm:w-auto sm:flex-row">
                <Button as-child variant="outline" size="lg">
                    <Link :href="backUrl" data-testid="connect-back">
                        {{ $t('accounts.connect.actions.back') }}
                    </Link>
                </Button>
                <Button v-if="retryUrl" as-child size="lg">
                    <a :href="retryUrl" data-testid="connect-retry">
                        {{
                            state === 'missing_permission'
                                ? $t('accounts.connect.actions.connect_again')
                                : state === 'expired'
                                  ? $t('accounts.connect.actions.start_again')
                                  : $t('accounts.connect.actions.try_again')
                        }}
                    </a>
                </Button>
            </div>
        </div>

        <template #footer>
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <Button variant="ghost" size="lg" data-testid="connect-help">
                        {{ $t('accounts.connect.help.label') }}
                        <IconChevronDown class="size-4" aria-hidden="true" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start" class="w-72">
                    <DropdownMenuItem as-child>
                        <a
                            :href="guideUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="cursor-pointer"
                            data-testid="connect-help-guide"
                        >
                            <PlatformLogo :platform="platform" size="xs" />
                            {{ $t('channels.details.help_guide', { network: networkName }) }}
                        </a>
                    </DropdownMenuItem>
                    <DropdownMenuItem as-child>
                        <a
                            :href="DOCS_URL"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="cursor-pointer"
                            data-testid="connect-help-docs"
                        >
                            {{ $t('channels.details.help_docs') }}
                        </a>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuLabel
                        class="text-xs font-normal text-muted-foreground"
                        data-testid="connect-help-missing"
                    >
                        {{ $t('accounts.connect.help.missing') }}
                    </DropdownMenuLabel>
                </DropdownMenuContent>
            </DropdownMenu>

            <Button
                v-if="isSwitchingAccount && switchUrl"
                as-child
                size="lg"
            >
                <a :href="switchUrl" data-testid="connect-switch-connect">
                    {{ $t('accounts.connect.switch.connect', { network: networkName }) }}
                </a>
            </Button>
            <Button
                v-else-if="isSelecting"
                size="lg"
                :disabled="selectedCount === 0 || form.processing"
                :loading="form.processing"
                data-testid="connect-finish"
                @click="finishConnection"
            >
                {{ $t('accounts.connect.finish') }}
            </Button>
        </template>
    </ConnectLayout>
</template>
