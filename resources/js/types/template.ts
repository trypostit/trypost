export type TemplateScope = 'discover' | 'team' | 'personal';

export type TemplateVisibility = 'personal' | 'team';

export type TemplateFacet = 'type' | 'audience' | 'format' | 'goal';

export interface LibraryTemplate {
    key: string;
    emoji: string;
    type: string;
    audiences: string[];
    format: string;
    goal: string;
    featured: boolean;
    title: string;
    description: string;
    body: string;
}

export interface PostTemplate {
    id: string;
    emoji: string | null;
    title: string;
    description: string | null;
    body: string;
    visibility: TemplateVisibility;
    author: string | null;
    can_edit: boolean;
    can_change_visibility: boolean;
    created_at: string | null;
}

export interface TemplateFacetFilters {
    types: string[];
    audiences: string[];
    formats: string[];
    goals: string[];
}

export interface TemplateFilters extends TemplateFacetFilters {
    search: string | null;
}

export interface TemplateCounts {
    discover: number;
    team: number;
    personal: number;
}

export interface TemplateRow {
    type: string;
    templates: LibraryTemplate[];
    total: number;
}

export interface TemplateLibraryProps {
    featured?: LibraryTemplate[];
    rows?: TemplateRow[];
    results?: LibraryTemplate[];
}

export interface PostTemplatePage {
    data: PostTemplate[];
}

export type TemplateModal =
    | null
    | { kind: 'library'; template: LibraryTemplate }
    | { kind: 'custom'; template: PostTemplate }
    | { kind: 'editor'; mode: 'create'; visibility: TemplateVisibility }
    | { kind: 'editor'; mode: 'edit'; template: PostTemplate };

export type DuplicateSource =
    | { kind: 'library'; key: string; title: string }
    | { kind: 'custom'; id: string; title: string };

export const isLibraryTemplate = (
    template: LibraryTemplate | PostTemplate,
): template is LibraryTemplate => 'key' in template;

export interface TemplatePickerData {
    library: LibraryTemplate[];
    team: PostTemplate[];
    personal: PostTemplate[];
    has_more: Record<TemplateVisibility, boolean>;
}
