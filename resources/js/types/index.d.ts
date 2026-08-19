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

export interface OnboardingProgress {
    completed: number;
    total: number;
}

export interface ContentTypeMediaRule {
    max_files: number;
    min_files: number | null;
    accept_images: boolean;
    accept_videos: boolean;
    accept_documents: boolean;
    requires_media: boolean;
    accepts_gif: boolean;
    forbids_mixed_media: boolean;
    max_image_bytes: number | null;
    max_video_bytes: number | null;
    max_document_bytes: number | null;
    max_video_duration_sec: number | null;
    aspect_ratio_min: number | null;
    aspect_ratio_max: number | null;
    auto_fits_image: boolean;
}

export interface SharedData {
    name: string;
    auth: Auth;
    flash: FlashData;
    onboardingProgress?: OnboardingProgress | false;
    sidebarOpen: boolean;
    selfHosted: boolean;
    contentTypeMediaRules?: Record<string, ContentTypeMediaRule>;
    [key: string]: unknown;
}

export type AppPageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & SharedData;

export interface User {
    id: string;
    name: string;
    first_name: string;
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

/** Per-account payload from ListPinterestBoards (Inertia + API/MCP). */
export interface PinterestBoardsPayload {
    boards: PinterestBoard[];
    truncated: boolean;
}

export interface ContentLanguageOption {
    value: string;
    label: string;
    englishName?: string;
}

/**
 * An AI content template, as serialized by PostController::create from an
 * AiContentTemplate. Shared by the post-creation screen and the AI wizard —
 * declaring it in both places is what let them drift apart before.
 */
export interface AiTemplate {
    key: string;
    name: string;
    description: string;
    preview: string;
    needs_account: boolean;
    supported_formats: string[];
    applies_brand_visuals: boolean;
}

