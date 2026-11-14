import type { TOURS } from '@/lib/tours';

/** A tour of `TOURS`, named by its key in `lang/<locale>/tours.php`. */
export type TourName = keyof typeof TOURS;

/** A step of any tour. */
export type TourStepName = (typeof TOURS)[TourName][number];

/** How a tour ended: its last step reached, or closed before. Mirrors `App\Enums\Tour\Outcome`. */
export type TourOutcome = 'completed' | 'skipped';

/** What the browser keeps of a tour the user went through: how it ended and the step reached, from 1. */
export interface TourProgress {
    status: TourOutcome;
    step: number;
}
