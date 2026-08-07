<?php

declare(strict_types=1);

return [
    'title' => 'Primi passi',
    'welcome' => 'Benvenuto su TryPost, :name',
    'welcome_anonymous' => 'Benvenuto su TryPost',
    'description' => 'Segui i passaggi qui sotto per vedere come funziona TryPost e pubblicare il tuo primo post.',
    'skip_step' => 'Salta questo passaggio',
    'continue' => 'Continua su TryPost',
    'status' => [
        'complete' => 'Completato',
        'todo' => 'Da fare',
        'skipped' => 'Saltato',
    ],
    'mcp' => [
        'title' => 'Collega il tuo assistente IA',
        'description' => 'Aggiungi TryPost come server MCP così il tuo assistente può creare e gestire i post social per te.',
        'copy_step' => 'Copia l’URL del server TryPost',
        'open_step' => 'Apri il tuo assistente IA',
        'copy' => 'Copia URL',
        'copied' => 'URL MCP copiato.',
        'connect' => 'Collega con :client',
        'clients' => [
            'claude' => 'Apri Settings → Connectors, aggiungi un connettore personalizzato e incolla l’URL qui sopra.',
            'chatgpt' => 'Apri Settings → Apps & Connectors, crea un connettore personalizzato e incolla l’URL qui sopra.',
        ],
    ],
    'social' => [
        'title' => 'Collega un account social',
        'description' => 'Scegli almeno una rete dove TryPost possa pubblicare i tuoi contenuti.',
        'connected_elsewhere' => 'Hai già collegato un account in un altro workspace, quindi questo passaggio è completato.',
    ],
    'first_post' => [
        'title' => 'Crea il tuo primo post',
        'description' => 'Prova questo prompt iniziale con il tuo assistente collegato, oppure crea il post direttamente in TryPost.',
        'prompt_label' => 'Prompt di esempio',
        'sample_prompt' => 'Crea un post social amichevole per presentare il mio brand e adattalo a ogni rete collegata.',
        'copy_prompt' => 'Copia prompt',
        'copied' => 'Prompt di esempio copiato.',
        'create_button' => 'Crea il tuo primo post',
        'or' => 'oppure',
    ],
    'ready' => [
        'title' => 'Sei pronto a pubblicare',
        'description' => 'Tutto a posto. Continua su TryPost e inizia a pianificare i tuoi contenuti.',
    ],
];
