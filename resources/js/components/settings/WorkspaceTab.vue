<script setup lang="ts">
import { Form } from '@inertiajs/vue3';

import WorkspaceController from '@/actions/App/Http/Controllers/App/WorkspaceController';
import PhotoUpload from '@/components/PhotoUpload.vue';
import DeleteWorkspace from '@/components/settings/DeleteWorkspace.vue';
import SettingsField from '@/components/settings/SettingsField.vue';
import SettingsSection from '@/components/settings/SettingsSection.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Separator } from '@/components/ui/separator';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { uploadLogo, deleteLogo } from '@/routes/app/workspace';

interface Workspace {
    id: string;
    name: string;
    has_logo: boolean;
    logo_url: string | null;
}

defineProps<{
    workspace: Workspace;
    isOnlyWorkspace: boolean;
    otherMemberCount: number;
}>();

const { canManageBilling } = useWorkspaceAbilities();
</script>

<template>
    <div class="flex flex-col gap-10">
        <SettingsSection
            :title="$t('settings.workspace.logo_heading')"
            :description="$t('settings.workspace.logo_description')"
        >
            <PhotoUpload
                :photo-url="workspace.logo_url"
                :has-photo="workspace.has_logo"
                :name="workspace.name"
                :upload-url="uploadLogo().url"
                :delete-url="deleteLogo().url"
                size="sm"
            />
        </SettingsSection>

        <Separator />

        <SettingsSection
            :title="$t('settings.workspace.heading')"
            :description="$t('settings.workspace.description')"
        >
            <Form
                v-bind="WorkspaceController.updateSettings.form()"
                v-slot="{ errors, processing }"
                class="flex flex-col gap-6"
            >
                <SettingsField
                    :label="$t('settings.workspace.name')"
                    for="name"
                    :error="errors.name"
                >
                    <Input
                        id="name"
                        name="name"
                        :default-value="workspace.name"
                        :placeholder="$t('settings.workspace.name_placeholder')"
                    />
                </SettingsField>

                <Button :disabled="processing" class="self-start">
                    {{ $t('settings.workspace.save') }}
                </Button>
            </Form>
        </SettingsSection>

        <template v-if="canManageBilling">
            <Separator />

            <DeleteWorkspace
                :workspace="workspace"
                :is-only-workspace="isOnlyWorkspace"
                :other-member-count="otherMemberCount"
            />
        </template>
    </div>
</template>
