import { InertiaLinkProps } from '@inertiajs/vue3';
import type { Component } from 'vue';

import type { ContentTypeMediaRule } from '@/lib/contentTypeMediaRules';
import type { CropPresetValue } from '@/lib/mediaEditor';
import type {
    DefaultPostAction,
    Theme,
    TimeFormat,
    WeekStart,
} from '@/preferences';
import type { AuthPlan, Features, PlanOption } from '@/types/plan';
import type { WelcomeSummary } from '@/types/welcome';

export type { AuthPlan, BillingInterval, Features, PlanOption } from '@/types/plan';
export type {
    WelcomeNetwork,
    WelcomeStep,
    WelcomeSummary,
} from '@/types/welcome';

export interface Workspace {
    id: string;
    name: string;
    logo_url: string | null;
    is_owner?: boolean;
    is_admin?: boolean;
    requires_approval?: boolean;
    [key: string]: unknown;
}

export interface AuthAccount {
    id: string;
    name: string;
    created_at: string | null;
}

export interface Auth {
    user: User;
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
}

export interface FlashData {
    banner?: string;
    bannerStyle?: 'success' | 'danger' | 'info' | 'warning';
    success?: string;
    error?: string;
    warning?: string;
    info?: string;
    plainToken?: string;
    createdLabel?: { id: string; name: string; color: string };
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
    count?: number;
    countTestId?: string;
}

export interface LegalLinks {
    terms: string;
    privacy: string;
}

export interface CanvaPresetOption {
    value: string;
    width: number;
    height: number;
    is_default: boolean;
}

export interface MediaSourceOption {
    source: 'google_drive' | 'google_photos' | 'canva' | 'unsplash';
    label: string;
    config: Record<string, string>;
    presets?: CanvaPresetOption[];
}

export interface MediaUploadLimits {
    max_bytes: { image: number; video: number; document: number };
    extensions: { image: string[]; video: string[]; document: string[] };
    upload_retention_hours: number;
    heic: boolean;
}

export interface SharedData {
    name: string;
    auth: Auth;
    flash: FlashData;
    selfHosted: boolean;
    legal: LegalLinks;
    contentTypeMediaRules?: Record<string, ContentTypeMediaRule>;
    defaultCropPresets?: CropPresetValue[];
    features?: Features | null;
    usage?: Usage | null;
    plans?: PlanOption[];
    mediaSources?: { menu: MediaSourceOption[] } | null;
    mediaUploadLimits?: MediaUploadLimits | null;
    welcome?: WelcomeSummary;
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
    timezone: string;
    theme: Theme;
    time_format: TimeFormat;
    week_starts_on: WeekStart;
    default_post_action: DefaultPostAction;
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
    cover_url?: string | null;
}

/** Per-account payload from ListPinterestBoards (Inertia + API/MCP). */
export interface PinterestBoardsPayload {
    boards: PinterestBoard[];
    truncated: boolean;
}

export interface Language {
    code: string;
    name: string;
    dir: string;
    flag: string;
}
