<?php

declare(strict_types=1);

return [
    'title' => 'Mídias',

    'tabs' => [
        'my_uploads' => 'Meus uploads',
        'stock_photos' => 'Fotos gratuitas',
        'gifs' => 'GIFs',
    ],

    'upload' => [
        'drag_drop' => 'Arraste e solte seus arquivos aqui ou clique para selecionar',
        'formats' => 'JPEG, PNG, GIF, WebP, MP4, PDF',
        'uploading' => 'Enviando...',
        'failed' => 'Não foi possível enviar :file. Tente novamente.',
        'file_too_large' => 'O tamanho do arquivo excede o máximo permitido (:max MB).',
        'cancelled' => 'Envio cancelado.',
    ],

    'empty' => [
        'title' => 'Nenhuma mídia ainda',
        'description' => 'Envie imagens e vídeos para criar sua biblioteca de mídia.',
    ],

    'save_to_assets' => 'Salvar na biblioteca',
    'saved' => 'Salvo na sua biblioteca!',
    'create_post' => 'Criar post',
    'add_to_post' => 'Adicionar ao post',
    'search_placeholder' => 'Buscar mídia...',

    'delete' => [
        'title' => 'Excluir mídia',
        'description' => 'Tem certeza que deseja excluir esta mídia? Esta ação não pode ser desfeita.',
        'confirm' => 'Excluir',
        'cancel' => 'Cancelar',
    ],

    'unsplash' => [
        'search_placeholder' => 'Buscar fotos gratuitas...',
        'no_results' => 'Nenhuma foto encontrada',
        'no_results_description' => 'Tente outro termo de busca.',
        'trending' => 'Em alta no Unsplash',
        'start_searching' => 'Busque fotos gratuitas do Unsplash',
    ],

    'giphy' => [
        'trending' => 'Em alta no Giphy',
        'search_placeholder' => 'Buscar GIFs...',
        'no_results' => 'Nenhum GIF encontrado',
        'no_results_description' => 'Tente outro termo de busca.',
        'powered_by' => 'Powered by GIPHY',
    ],
];
