<script setup lang="ts">
import { Form } from '@inertiajs/vue3';

import WorkspaceController from '@/actions/App/Http/Controllers/App/WorkspaceController';
import DeleteWorkspace from '@/components/settings/DeleteWorkspace.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import PhotoUpload from '@/components/PhotoUpload.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { useWorkspaceRole } from '@/composables/useWorkspaceRole';
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

const { canManageBilling } = useWorkspaceRole();
</script>

<template>
    <div class="space-y-12">
        <div class="flex flex-col space-y-6">
            <HeadingSmall
                :title="$t('settings.workspace.logo_heading')"
                :description="$t('settings.workspace.logo_description')"
            />

            <PhotoUpload
                :photo-url="workspace.logo_url"
                :has-photo="workspace.has_logo"
                :name="workspace.name"
                :upload-url="uploadLogo().url"
                :delete-url="deleteLogo().url"
            />
        </div>

        <Separator />

        <div class="flex flex-col space-y-6">
            <HeadingSmall
                :title="$t('settings.workspace.heading')"
                :description="$t('settings.workspace.description')"
            />

            <Form
                v-bind="WorkspaceController.updateSettings.form()"
                v-slot="{ errors, processing }"
                class="space-y-6"
            >
                <div class="grid gap-2">
                    <Label for="name">{{ $t('settings.workspace.name') }}</Label>
                    <Input
                        id="name"
                        name="name"
                        :default-value="workspace.name"
                        :placeholder="$t('settings.workspace.name_placeholder')"
                    />
                    <InputError :message="errors.name" />
                </div>

                <Button :disabled="processing">{{ $t('settings.workspace.save') }}</Button>
            </Form>
        </div>

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
