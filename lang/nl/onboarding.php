<?php

declare(strict_types=1);

return [
    'title' => 'Aan de slag',
    'welcome' => 'Welkom bij TryPost, :name',
    'welcome_anonymous' => 'Welkom bij TryPost',
    'description' => 'Volg de stappen hieronder om te zien hoe TryPost werkt en je eerste post te publiceren.',
    'skip_step' => 'Deze stap overslaan',
    'continue' => 'Doorgaan naar TryPost',
    'status' => [
        'complete' => 'Voltooid',
        'todo' => 'Te doen',
        'skipped' => 'Overgeslagen',
    ],
    'mcp' => [
        'title' => 'Koppel je AI-assistent',
        'description' => 'Voeg TryPost toe als MCP-server zodat je assistent social posts voor je kan maken en beheren.',
        'copy_step' => 'Kopieer je TryPost-server-URL',
        'open_step' => 'Open je AI-assistent',
        'copy' => 'URL kopiëren',
        'copied' => 'MCP-URL gekopieerd.',
        'connect' => 'Verbinden met :client',
        'clients' => [
            'claude' => 'Open Settings → Connectors, voeg een aangepaste connector toe en plak de URL hierboven.',
            'chatgpt' => 'Open Settings → Apps & Connectors, maak een aangepaste connector aan en plak de URL hierboven.',
        ],
    ],
    'social' => [
        'title' => 'Koppel een social account',
        'description' => 'Kies minstens één netwerk waar TryPost je content kan publiceren.',
        'connected_elsewhere' => 'Je hebt al een account gekoppeld in een andere workspace, dus deze stap is klaar.',
    ],
    'first_post' => [
        'title' => 'Maak je eerste post',
        'description' => 'Probeer deze startprompt met je gekoppelde assistent, of maak de post direct in TryPost.',
        'prompt_label' => 'Voorbeeldprompt',
        'sample_prompt' => 'Maak een vriendelijke social post die mijn merk introduceert en pas die aan voor elk gekoppeld netwerk.',
        'copy_prompt' => 'Prompt kopiëren',
        'copied' => 'Voorbeeldprompt gekopieerd.',
        'create_button' => 'Je eerste post maken',
        'or' => 'of',
    ],
    'ready' => [
        'title' => 'Je bent klaar om te publiceren',
        'description' => 'Alles staat. Ga door naar TryPost en begin je content te plannen.',
    ],
];
