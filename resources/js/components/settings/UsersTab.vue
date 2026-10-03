<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import {
    IconClock,
    IconDotsVertical,
    IconShield,
    IconTrash,
} from '@tabler/icons-vue';
import { computed, ref } from 'vue';

import ConfirmDeleteModal from '@/components/ConfirmDeleteModal.vue';
import EditMemberDialog from '@/components/members/EditMemberDialog.vue';
import InviteMemberDialog from '@/components/members/InviteMemberDialog.vue';
import { Avatar } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useWorkspaceAbilities } from '@/composables/useWorkspaceAbilities';
import { destroy as destroyInvite } from '@/routes/app/invites';
import { remove as removeMemberRoute } from '@/routes/app/members';
import {
    memberAccessLabelKey,
    type WorkspaceInvitation,
    type WorkspaceMember,
} from '@/types/members';

const props = defineProps<{
    members: WorkspaceMember[];
    invitations: WorkspaceInvitation[];
    ownerId: string | null;
}>();

const page = usePage();
const currentUserId = computed(() => page.props.auth.user.id);

const { canManageTeam } = useWorkspaceAbilities();

const inviteOpen = defineModel<boolean>('inviteOpen', { default: false });
const editOpen = ref(false);
const editing = ref<WorkspaceMember | null>(null);
const removeMemberModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);
const cancelInvitationModal = ref<InstanceType<typeof ConfirmDeleteModal> | null>(null);

const isManageable = (member: WorkspaceMember): boolean =>
    canManageTeam.value &&
    member.id !== currentUserId.value &&
    member.id !== props.ownerId;

const editMember = (member: WorkspaceMember): void => {
    editing.value = member;
    editOpen.value = true;
};
</script>

<template>
    <div class="flex flex-col">
        <ul class="flex flex-col gap-2">
            <li
                v-for="member in members"
                :key="member.id"
                class="flex items-center gap-4 rounded-xl border border-border bg-card p-4"
                :data-testid="`member-row-${member.id}`"
            >
                <Avatar
                    :src="member.photo_url"
                    :name="member.name"
                    class="size-12 rounded-lg"
                    fallback-class="bg-sidebar-accent text-sidebar-accent-foreground"
                />
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <p
                            class="truncate text-sm leading-tight font-emphasis text-foreground"
                        >
                            {{ member.name }}
                        </p>
                        <Badge
                            variant="secondary"
                            :data-testid="`member-badge-${member.id}`"
                        >
                            {{ $t(memberAccessLabelKey(member, member.id === ownerId)) }}
                        </Badge>
                    </div>
                    <p class="truncate text-sm text-muted-foreground">
                        {{ member.email }}
                    </p>
                </div>
                <DropdownMenu v-if="isManageable(member)">
                    <DropdownMenuTrigger as-child>
                        <Button
                            variant="ghost"
                            size="icon"
                            class="shrink-0 text-muted-foreground data-[state=open]:bg-accent"
                            :aria-label="member.name"
                            :data-testid="`member-menu-${member.id}`"
                        >
                            <IconDotsVertical class="size-4" />
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            :data-testid="`member-edit-${member.id}`"
                            @click="editMember(member)"
                        >
                            <IconShield class="size-4" />
                            {{ $t('settings.members.edit.action') }}
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            variant="destructive"
                            @click="
                                removeMemberModal?.open({
                                    url: removeMemberRoute.url(member.id),
                                    confirmText: member.email,
                                })
                            "
                        >
                            <IconTrash class="size-4" />
                            {{ $t('settings.members.remove') }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </li>
            <li
                v-for="invitation in invitations"
                :key="`inv-${invitation.id}`"
                class="flex items-center gap-4 rounded-xl border border-border bg-card p-4"
            >
                <span
                    class="inline-flex size-12 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground"
                >
                    <IconClock class="size-5" />
                </span>
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <div class="flex min-w-0 flex-wrap items-center gap-2">
                        <p
                            class="truncate text-sm leading-tight font-emphasis text-foreground"
                        >
                            {{ invitation.email }}
                        </p>
                        <Badge variant="secondary">
                            {{ $t(memberAccessLabelKey(invitation)) }}
                        </Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        {{ $t('settings.members.pending.title') }}
                    </p>
                </div>
                <Button
                    v-if="canManageTeam"
                    variant="ghost"
                    size="icon"
                    class="shrink-0 text-destructive-text"
                    :aria-label="$t('settings.members.cancel_invite_modal.action')"
                    @click="
                        cancelInvitationModal?.open({
                            url: destroyInvite.url(invitation.id),
                            confirmText: invitation.email,
                        })
                    "
                >
                    <IconTrash class="size-4" />
                </Button>
            </li>
        </ul>

        <InviteMemberDialog v-model:open="inviteOpen" />
        <EditMemberDialog v-model:open="editOpen" :member="editing" />

        <ConfirmDeleteModal
            ref="removeMemberModal"
            :title="$t('settings.members.remove_modal.title')"
            :description="$t('settings.members.remove_modal.description')"
            :action="$t('settings.members.remove_modal.action')"
        />

        <ConfirmDeleteModal
            ref="cancelInvitationModal"
            :title="$t('settings.members.cancel_invite_modal.title')"
            :description="$t('settings.members.cancel_invite_modal.description')"
            :action="$t('settings.members.cancel_invite_modal.action')"
        />
    </div>
</template>
