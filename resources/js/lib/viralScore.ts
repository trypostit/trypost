/**
 * Instant, credit-free "viral potential" analysis used to build excitement in
 * the creation flow. It reads the REAL signals of the drafted content (hook,
 * CTA, length, hashtags, media, carousel arc) and maps them into a confident
 * 80–97 band. Deterministic per content, so the same post always scores the
 * same and it recalculates live as the user edits — never a random number.
 */

export interface ViralScoreInput {
    /** Caption + every slide's text, concatenated. */
    text: string;
    /** Carousel slides (1 for a single post). */
    slideCount?: number;
    /** Attached images/videos. */
    mediaCount?: number;
    /** Platforms the post targets. */
    platformCount?: number;
}

export interface ViralFactor {
    key: string;
    hit: boolean;
    weight: number;
}

export interface ViralScoreResult {
    /** Integer in [80, 97]. */
    score: number;
    factors: ViralFactor[];
}

export const VIRAL_MIN = 80;
export const VIRAL_MAX = 97;

const CTA_CUES = [
    // pt-BR
    'comente',
    'comenta',
    'salve',
    'salva',
    'compartilhe',
    'compartilha',
    'marque',
    'agende',
    'clique',
    'acesse',
    'baixe',
    'inscreva',
    'siga',
    'chama no direct',
    'manda',
    'responde',
    'link na bio',
    // en
    'comment',
    'save this',
    'share',
    'tag a',
    'follow',
    'click',
    'download',
    'sign up',
    'dm me',
    'book a',
    'link in bio',
    // es
    'comenta',
    'guarda',
    'comparte',
    'sigue',
    'agenda',
    'escríbeme',
];

const firstLine = (text: string): string =>
    (text.split('\n').find((l) => l.trim().length > 0) ?? '').trim();

const stableHash = (text: string): number => {
    let hash = 2166136261;
    for (let i = 0; i < text.length; i += 1) {
        hash ^= text.charCodeAt(i);
        hash = Math.imul(hash, 16777619);
    }
    return Math.abs(hash);
};

export const viralScore = (input: ViralScoreInput): ViralScoreResult => {
    const text = (input.text ?? '').trim();
    const lower = text.toLowerCase();
    const hook = firstLine(text);
    const words = text.split(/\s+/).filter(Boolean).length;

    const factors: ViralFactor[] = [
        // A hook that is punchy but not a fragment, ideally a question or a number.
        {
            key: 'hook',
            weight: 6,
            hit: hook.length >= 18 && hook.length <= 110,
        },
        { key: 'hook_tension', weight: 3, hit: /\?|\d/.test(hook) },
        // A clear call to action.
        {
            key: 'cta',
            weight: 4,
            hit: CTA_CUES.some((cue) => lower.includes(cue)),
        },
        // Enough substance without being a wall of text.
        { key: 'length', weight: 4, hit: words >= 30 && words <= 260 },
        // Scannable rhythm (paragraph breaks).
        { key: 'scannable', weight: 2, hit: text.includes('\n\n') },
        // Discoverability.
        {
            key: 'hashtags',
            weight: 3,
            hit: (text.match(/#\w+/g) ?? []).length >= 2,
        },
        // Visuals attached.
        { key: 'media', weight: 3, hit: (input.mediaCount ?? 0) >= 1 },
        // A carousel arc holds attention across slides.
        { key: 'carousel', weight: 3, hit: (input.slideCount ?? 1) >= 3 },
        // Cross-posting multiplies reach.
        { key: 'reach', weight: 2, hit: (input.platformCount ?? 1) >= 2 },
    ];

    const gained = factors.reduce((sum, f) => sum + (f.hit ? f.weight : 0), 0);
    const maxGain = factors.reduce((sum, f) => sum + f.weight, 0);
    const ratio = maxGain > 0 ? gained / maxGain : 0;

    // Real content clusters around 0.25–0.9 of the max signals, so a raw
    // ratio would bunch every score near the floor. Stretch that realistic
    // window across the whole band so weak drafts sit low and strong ones
    // actually reach the 90s — a visible spread, not everything at ~80.
    const normalized = Math.max(0, Math.min(1, (ratio - 0.2) / 0.65));

    const span = VIRAL_MAX - VIRAL_MIN; // 17
    const base = VIRAL_MIN + Math.round(normalized * (span - 2));
    // Stable per-content nudge so the number reads as "analyzed" (87, 93) not round.
    const jitter = text.length > 0 ? stableHash(text) % 3 : 0;

    const score = Math.max(VIRAL_MIN, Math.min(VIRAL_MAX, base + jitter));

    return { score, factors };
};

export type ViralBand = 'good' | 'great' | 'exceptional';

export const viralBand = (score: number): ViralBand => {
    if (score >= 92) {
        return 'exceptional';
    }
    if (score >= 86) {
        return 'great';
    }
    return 'good';
};

/**
 * Heat colour for the gauge number/label: the draft "heats up" as it gets
 * stronger — warm amber at the low end, orange in the middle, red-hot when it
 * is genuinely viral (matches the flame + heatmap ring).
 */
export const viralColor = (score: number): string => {
    const band = viralBand(score);
    if (band === 'exceptional') {
        return '#ef4444'; // red-hot
    }
    if (band === 'great') {
        return '#f97316'; // orange
    }
    return '#eab308'; // amber
};

/**
 * Ordered heatmap ramp (cool → hot) painted along the gauge arc so the ring
 * shows many colours and visibly "heats up" toward the tip.
 */
export const VIRAL_HEATMAP = [
    '#3b82f6',
    '#22c55e',
    '#eab308',
    '#f97316',
    '#ef4444',
];
