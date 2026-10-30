<style>
    :root {
        --foreground: #292928;
        --muted-foreground: #5a5a59;
        --subtle-foreground: #6f6e6c;
        --border: #eae8e5;
        --muted: #f7f6f3;
        --accent: #f3f2f0;
        --primary-selected: #ede4fd;
        --primary-text: #6d28d9;
        --success-text: #337046;
        --destructive-text: #7f0f00;
    }

    * { box-sizing: border-box; }

    html, body {
        margin: 0;
        color: var(--foreground);
        font-family: Inter, 'Noto Sans', 'Noto Sans CJK SC', 'Noto Color Emoji', sans-serif;
        font-size: 10px;
        line-height: 1.45;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    h1, h2, h3, .big, .chart-total {
        font-family: Outfit, Inter, 'Noto Sans', sans-serif;
        font-weight: 500;
        margin: 0;
    }

    p { margin: 0; }

    .muted { color: var(--muted-foreground); }
    .small { font-size: 9px; }
    .block { display: block; }
    .label { color: var(--muted-foreground); font-size: 9.5px; }

    .cover {
        display: flex;
        flex-direction: column;
        min-height: 255mm;
        padding: 18mm 4mm 0;
        page-break-after: always;
    }

    .cover-logo { max-height: 56px; max-width: 220px; object-fit: contain; }

    .cover-mark {
        display: inline-block;
        width: 44px;
        height: 44px;
        border-radius: 999px;
        background: conic-gradient(from 225deg, #855ed4 0 25%, #3660e0 0 50%, #f39200 0 75%, #ffcc33 0 100%);
    }

    .cover-body { margin-top: 70mm; }
    .eyebrow { color: var(--primary-text); font-size: 10px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; }
    .cover-title { font-size: 34px; line-height: 1.15; margin: 8px 0 14px; }
    .cover-range { font-size: 14px; margin-bottom: 4px; }
    .cover-channels { margin-top: 18mm; }
    .cover-generated { margin-top: auto; padding-bottom: 6mm; }

    .chip-list { display: flex; flex-wrap: wrap; gap: 10px 22px; margin-top: 8px; }

    .section { break-inside: auto; margin-bottom: 9mm; }
    .channel-part { page-break-before: always; }

    .heading { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
    .heading h2 { font-size: 15px; }
    .subheading { font-size: 12px; margin: 14px 0 8px; }

    .channel-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }

    .account { display: inline-flex; align-items: center; gap: 8px; min-width: 0; }
    .account-text { display: flex; flex-direction: column; min-width: 0; }
    .account-name { font-weight: 600; }
    .account-large .avatar { width: 36px; height: 36px; border-radius: 10px; }
    .account-large .account-name { font-size: 13px; }

    .avatar {
        width: 26px;
        height: 26px;
        border-radius: 7px;
        object-fit: cover;
        flex-shrink: 0;
        background: var(--accent);
    }

    .avatar-empty {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        color: var(--muted-foreground);
        font-size: 10px;
        font-weight: 600;
    }

    .summary {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        border: 1px solid var(--border);
        border-radius: 12px;
        overflow: hidden;
        break-inside: avoid;
    }

    .summary-cell { padding: 10px 12px; box-shadow: 1px 0 0 var(--border), 0 1px 0 var(--border); }
    .summary-value { display: flex; flex-wrap: wrap; align-items: center; gap: 4px 8px; margin-top: 4px; }
    .big { font-size: 18px; line-height: 1.2; }

    .change { display: inline-flex; align-items: center; gap: 3px; font-weight: 600; font-size: 10px; white-space: nowrap; }
    .change svg { width: 12px; height: 12px; fill: none; stroke: currentColor; stroke-width: 2.25; stroke-linecap: round; stroke-linejoin: round; }
    .change-compact { font-size: 8.5px; }
    .change-compact svg { width: 10px; height: 10px; }
    .change-up { color: var(--success-text); }
    .change-down { color: var(--destructive-text); }
    .change-flat { color: var(--muted-foreground); }

    .top-posts {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        border: 1px solid var(--border);
        border-radius: 12px;
        overflow: hidden;
        break-inside: avoid;
    }

    .top-post {
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 10px;
        box-shadow: 1px 0 0 var(--border), 0 1px 0 var(--border);
        min-width: 0;
    }

    .top-post-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 6px; }
    .top-post-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
    .top-post-body { display: flex; gap: 6px; }
    .top-post-via { margin-top: auto; padding-top: 6px; border-top: 1px solid var(--border); text-align: center; }

    .rank-badge, .rank {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 22px;
        height: 18px;
        padding: 0 5px;
        border-radius: 6px;
        background: var(--primary-selected);
        color: var(--primary-text);
        font-size: 9px;
        font-weight: 600;
    }

    .excerpt {
        display: -webkit-box;
        -webkit-line-clamp: 4;
        -webkit-box-orient: vertical;
        overflow: hidden;
        word-break: break-word;
    }

    .thumb { width: 36px; height: 36px; border-radius: 8px; object-fit: cover; flex-shrink: 0; background: var(--accent); }
    .thumb-empty { display: inline-block; }

    .charts { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; margin-top: 10px; }

    .chart {
        border: 1px solid var(--border);
        border-radius: 12px;
        padding: 10px 12px 6px;
        break-inside: avoid;
    }

    .chart-head { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 4px; }
    .chart-total { font-size: 14px; }

    .table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        border: 1px solid var(--border);
        border-radius: 12px;
        overflow: hidden;
    }

    .table th {
        background: var(--muted);
        color: var(--muted-foreground);
        font-size: 9px;
        font-weight: 500;
        text-align: start;
        padding: 7px 10px;
        border-bottom: 1px solid var(--border);
    }

    .table td { padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
    .table tr:last-child td { border-bottom: 0; }
    .table tr { break-inside: avoid; }
    .table thead { display: table-header-group; }
    .num { text-align: end; white-space: nowrap; }
    th.num { text-align: end; }
    .value { font-weight: 500; }
    .num .change { margin-inline-start: 6px; }

    .post-cell { display: flex; align-items: center; gap: 8px; max-width: 300px; }
    .post-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; }
    .post-text .excerpt { -webkit-line-clamp: 2; }

    .note { color: var(--muted-foreground); font-size: 9px; margin-top: 6px; }

    .empty {
        border: 1px dashed #dedcd9;
        border-radius: 12px;
        padding: 18px;
        text-align: center;
        color: var(--muted-foreground);
    }
</style>
