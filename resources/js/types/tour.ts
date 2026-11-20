import type { TOURS } from '@/lib/tours';

/** A tour of `TOURS`, named by its key in `lang/<locale>/tours.php`. */
export type TourName = keyof typeof TOURS;

/** A step of the given tour (of any tour when none is given). */
export type TourStepName<Tour extends TourName = TourName> =
    (typeof TOURS)[Tour][number];

/** How a tour ended: its last step reached, or closed before. Mirrors `App\Enums\Tour\Outcome`. */
export type TourOutcome = 'completed' | 'skipped';

/** What the browser keeps of a tour the user went through: how it ended and the step reached, from 1. */
export interface TourProgress {
    status: TourOutcome;
    step: number;
}

/** Where the open step's control is on screen, in pixels. */
export interface TourSpotlight {
    top: number;
    left: number;
    width: number;
    height: number;
}

/** What the open step shows and does, drawn by the one overlay of every tour (`TourOverlay`). */
export interface OpenTourStep {
    tour: TourName;
    step: string;
    position: number;
    total: number;
    tag?: string;
    tagColor: string;
    align: 'start' | 'center' | 'end';
    control: HTMLElement;
    frame: TourSpotlight;
    next: () => void;
    back: () => void;
    skip: () => void;
}
