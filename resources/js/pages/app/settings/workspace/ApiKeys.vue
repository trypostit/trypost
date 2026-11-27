<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import {
    IconDotsVertical,
    IconKey,
    IconPlus,
    IconRefresh,
    IconTrash,
} from '@tabler/icons-vue';
import { ref, watch } from 'vue';

import ApiKeyController from '@/actions/App/Http/Controllers/App/ApiKeyController';
import ApiKeyGeneratedDialog from '@/components/api-keys/ApiKeyGeneratedDialog.vue';
import CreateApiKeyDialog from '@/components/api-keys/CreateApiKeyDialog.vue';
import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EmptyState from '@/components/EmptyState.vue';
import ApiKeysEmptyIllustration from '@/components/settings/ApiKeysEmptyIllustration.vue';
import SettingsListRow from '@/components/settings/SettingsListRow.vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import date from '@/date';
import SettingsLayout from '@/layouts/SettingsLayout.vue';

type ApiTokenStatus = 'active' | 'expiring_soon' | 'expired';

interface ApiToken {
    id: string;
    name: string;
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string;
    status: ApiTokenStatus;
}

interface Props {
    apiTokens: ApiToken[];
}

defineProps<Props>();

const STATUS_DOT: Record<ApiTokenStatus, string> = {
    active: 'bg-success',
    expiring_soon: 'bg-warning',
    expired: 'bg-destructive',
};

const STATUS_TEXT: Record<ApiTokenStatus, string> = {
    active: '',
    expiring_soon: 'text-warning',
    expired: 'text-destructive-text',
};

const page = usePage();
const generatedKey = ref('');
const generatedDialogOpen = ref(false);

watch(
    () => (page.props.flash as Record<string, unknown>)?.plainToken as
        | string
        | undefined,
    (token) => {
        if (token) {
            generatedKey.value = token;
            generatedDialogOpen.value = true;
        }
    },
    { immediate: true },
);

const onGeneratedDialogChange = (isOpen: boolean) => {
    generatedDialogOpen.value = isOpen;

    if (!isOpen) {
        generatedKey.value = '';
    }
};

const createDialogOpen = ref(false);

const openCreateDialog = (): void => {
    createDialogOpen.value = true;
};

const confirmDeleteModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(
    null,
);
const confirmRegenerateModal = ref<InstanceType<
    typeof ConfirmDeleteModal
> | null>(null);
</script>

<template>
    <Head :title="$t('settings.api_keys.page_title')" />

    <SettingsLayout
        :title="$t('settings.api_keys.page_title')"
        :description="$t('settings.api_keys.description')"
        :centered="apiTokens.length === 0"
    >
        <template #actions>
            <Button
                data-testid="create-api-key-button"
                @click="openCreateDialog"
            >
                <IconPlus class="size-4" />
                {{ $t('settings.api_keys.create') }}
            </Button>
        </template>

        <div class="flex flex-col gap-3">
            <ul v-if="apiTokens.length > 0" class="flex flex-col gap-2">
                <SettingsListRow
                    v-for="token in apiTokens"
                    :key="token.id"
                    :data-testid="`api-key-row-${token.id}`"
                >
                    <template #media>
                        <span
                            class="relative inline-flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                        >
                            <IconKey class="size-5" />
                            <span
                                :class="[
                                    'absolute -end-0.5 -bottom-0.5 size-3 rounded-full ring-2 ring-card',
                                    STATUS_DOT[token.status],
                                ]"
                                :data-testid="`api-key-status-dot-${token.id}`"
                            />
                        </span>
                    </template>
                    <p
                        class="truncate text-sm leading-tight font-emphasis text-foreground"
                    >
                        {{ token.name }}
                    </p>
                    <p
                        class="flex flex-wrap items-center gap-x-1.5 text-sm text-muted-foreground"
                    >
                        <span
                            :class="STATUS_TEXT[token.status]"
                            :data-testid="`api-key-expiry-${token.id}`"
                            >{{
                                token.expires_at
                                    ? $t(
                                          token.status === 'expired'
                                              ? 'settings.api_keys.meta.expired'
                                              : 'settings.api_keys.meta.expires',
                                          {
                                              date: date.formatDate(
                                                  token.expires_at,
                                              ),
                                          },
                                      )
                                    : $t('settings.api_keys.meta.never_expires')
                            }}</span
                        >
                        <span aria-hidden="true">·</span>
                        <span>{{
                            token.last_used_at
                                ? $t('settings.api_keys.meta.last_used', {
                                      time: date.diffForHumans(
                                          token.last_used_at,
                                      ),
                                  })
                                : $t('settings.api_keys.meta.never_used')
                        }}</span>
                        <span aria-hidden="true">·</span>
                        <span>{{
                            $t('settings.api_keys.meta.created', {
                                date: date.formatDate(token.created_at),
                            })
                        }}</span>
                    </p>
                    <template #actions>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    class="shrink-0 text-muted-foreground data-[state=open]:bg-accent"
                                    :aria-label="token.name"
                                    :data-testid="`api-key-menu-${token.id}`"
                                >
                                    <IconDotsVertical class="size-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuItem
                                    :data-testid="`regenerate-api-key-${token.id}`"
                                    @click="
                                        confirmRegenerateModal?.open({
                                            url: ApiKeyController.regenerate.url(
                                                token.id,
                                            ),
                                            confirmText: $t(
                                                'settings.api_keys.regenerate_modal.keyword',
                                            ),
                                        })
                                    "
                                >
                                    <IconRefresh class="size-4" />
                                    {{
                                        $t('settings.api_keys.actions.regenerate')
                                    }}
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem
                                    variant="destructive"
                                    :data-testid="`delete-api-key-${token.id}`"
                                    @click="
                                        confirmDeleteModal?.open({
                                            url: ApiKeyController.destroy.url(
                                                token.id,
                                            ),
                                            confirmText: $t(
                                                'common.confirm_modal.delete_keyword',
                                            ),
                                        })
                                    "
                                >
                                    <IconTrash class="size-4" />
                                    {{ $t('settings.api_keys.actions.delete') }}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </template>
                </SettingsListRow>
            </ul>

            <EmptyState
                v-else
                :title="$t('settings.api_keys.empty.title')"
                :description="$t('settings.api_keys.empty.description')"
                data-testid="api-keys-empty"
            >
                <template #illustration>
                    <ApiKeysEmptyIllustration />
                </template>
                <template #action>
                    <Button
                        data-testid="api-keys-empty-create"
                        @click="openCreateDialog"
                    >
                        <IconPlus class="size-4" />
                        {{ $t('settings.api_keys.create') }}
                    </Button>
                </template>
            </EmptyState>
        </div>
    </SettingsLayout>

    <CreateApiKeyDialog v-model:open="createDialogOpen" />

    <ApiKeyGeneratedDialog
        v-if="generatedKey"
        :open="generatedDialogOpen"
        :api-key="generatedKey"
        @update:open="onGeneratedDialogChange"
    />

    <ConfirmDeleteModal
        ref="confirmDeleteModal"
        :title="$t('settings.api_keys.delete_modal.title')"
        :description="$t('settings.api_keys.delete_modal.description')"
        :action="$t('settings.api_keys.delete_modal.action')"
    />

    <ConfirmDeleteModal
        ref="confirmRegenerateModal"
        method="post"
        :title="$t('settings.api_keys.regenerate_modal.title')"
        :description="$t('settings.api_keys.regenerate_modal.description')"
        :action="$t('settings.api_keys.regenerate_modal.action')"
    />
</template>
