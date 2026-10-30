/** The PDF sections of each Insights page, mirroring `App\Enums\Analytics\PdfSection`. */
export const WORKSPACE_PDF_SECTIONS = [
    'summary',
    'top_posts_reactions',
    'top_posts_comments',
    'performance',
    'channels',
] as const;

export const CHANNEL_PDF_SECTIONS = ['summary', 'metrics', 'posts'] as const;
