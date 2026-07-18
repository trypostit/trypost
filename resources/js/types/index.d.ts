import { InertiaLinkProps } from '@inertiajs/vue3';
import type { Component } from 'vue';

export type WorkspaceRole = 'owner' | 'admin' | 'member' | 'viewer';

export interface Workspace {
    id: string;
    name: string;
    logo_url: string | null;
    role?: WorkspaceRole | null;
    [key: string]: unknown;
}

export interface AuthPlan {
    id: string;
    slug: string;
    name: string;
    interval: 'monthly' | 'yearly';
}

export interface AuthAccount {
    id: string;
    name: string;
    created_at: string | null;
}

export interface Auth {
    user: User;
    role: WorkspaceRole | null;
    currentWorkspace: Workspace | null;
    workspaces: Workspace[];
    account: AuthAccount | null;
    plan: AuthPlan | null;
    hasActiveSubscription: boolean;
    subscriptionPastDue: boolean;
}

export interface Usage {
    workspaceCount: number;
    socialAccountCount: number;
    memberCount: number;
    pendingInviteCount: number;
    postCount: number;
    creditsUsed: number;
}

export interface FlashData {
    banner?: string;
    bannerStyle?: 'success' | 'danger' | 'info' | 'warning';
    plainToken?: string;
    [key: string]: unknown;
}

export interface NavItem {
    title: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: Component;
    isActive?: boolean;
    activePattern?: string;
    exact?: boolean;
    excludeActive?: string[];
    badge?: string;
}

export interface SharedData {
    name: string;
    auth: Auth;
    flash: FlashData;
    sidebarOpen: boolean;
    selfHosted: boolean;
    [key: string]: unknown;
}

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & SharedData;

export interface User {
    id: string;
    name: string;
    email: string;
    has_photo: boolean;
    photo_url: string | null;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
}

export type BreadcrumbItem = {
    title: string;
    href?: string;
};

export interface PinterestBoard {
    id: string;
    name: string;
}

export interface ContentLanguageOption {
    value: string;
    label: string;
    englishName?: string;
}

