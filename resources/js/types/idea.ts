import type { InjectionKey } from 'vue';

import type { MediaItem } from '@/types/media';

export type CreateTab = 'ideas' | 'templates' | 'feeds';

export interface IdeaStage {
    id: string;
    name: string;
    ideas_count?: number;
}

export interface Idea {
    id: string;
    idea_stage_id: string | null;
    title: string | null;
    body: string | null;
    media: MediaItem[];
    label_ids: string[];
}

export interface IdeaCard {
    id: string;
    idea_stage_id: string | null;
    title: string | null;
    excerpt: string;
    cover: MediaItem | null;
    label_ids: string[];
}

export type IdeasView = 'board' | 'gallery';

export type IdeaEditorState =
    | { mode: 'create'; idea_stage_id: string | null }
    | { mode: 'edit'; idea: Idea };

export interface IdeaLabel {
    id: string;
    name: string;
    color: string;
}

export interface IdeaFilters {
    stages: string[];
    labels: string[];
    untagged: boolean;
    unassigned: boolean;
}

export interface IdeaCardPage {
    data: IdeaCard[];
}

export const UNASSIGNED = 'unassigned';

export const columnKey = (stageId: string | null): string =>
    stageId ?? UNASSIGNED;

export interface IdeaCardActions {
    open: (card: IdeaCard) => void;
    toggle: (card: IdeaCard) => void;
    select: (card: IdeaCard) => void;
    move: (card: IdeaCard, stageId: string | null) => void;
    duplicate: (card: IdeaCard) => void;
    remove: (card: IdeaCard) => void;
}

export const ideaCardActionsKey: InjectionKey<IdeaCardActions> =
    Symbol('ideaCardActions');
