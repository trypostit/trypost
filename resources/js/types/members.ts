export interface MemberAccess {
    is_admin: boolean;
    requires_approval: boolean;
}

export interface WorkspaceMember extends MemberAccess {
    id: string;
    name: string;
    email: string;
    photo_url: string | null;
}

export interface WorkspaceInvitation extends MemberAccess {
    id: string;
    email: string;
}

export const memberAccessLabelKey = (
    access: MemberAccess,
    isOwner = false,
): string => {
    if (isOwner) {
        return 'settings.members.roles.owner';
    }

    if (access.is_admin) {
        return 'settings.members.roles.admin';
    }

    return access.requires_approval
        ? 'settings.members.access.needs_approval'
        : 'settings.members.roles.member';
};
