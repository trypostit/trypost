<?php

declare(strict_types=1);

return [

    'layout' => [
        'tagline' => 'Enviado pela equipe :brand',
        'manage_notifications' => 'Gerenciar notificações',
        'signoff' => 'Atenciosamente,',
        'team' => 'A equipe TryPost',
    ],

    'account_disconnected' => [
        'subject' => 'Sua conta do :platform em :workspace precisa ser reconectada',
        'title' => 'Sua conta do :platform precisa ser reconectada',
        'preview' => 'Reconecte sua conta do :platform em :workspace para continuar agendando publicações.',
        'heading' => 'Conta desconectada',
        'intro' => 'A conta abaixo foi desconectada da área de trabalho :workspace.',
        'reasons_title' => 'Isso pode ter acontecido porque:',
        'reason_expired' => 'Seu token de acesso expirou',
        'reason_revoked' => 'Você revogou o acesso do TryPost',
        'reason_error' => 'Ocorreu um erro de autenticação',
        'reconnect_cta' => 'Reconecte sua conta para continuar agendando e publicando.',
        'button' => 'Reconectar conta',
    ],

    'email_verification' => [
        'subject' => 'Confirme seu endereço de e-mail',
        'preview' => 'Confirme seu endereço de e-mail.',
        'greeting' => 'Olá :name,',
        'body' => 'Confirme seu endereço de e-mail clicando no botão abaixo:',
        'button' => 'Confirmar e-mail',
        'ignore' => 'Se você não criou uma conta, pode ignorar este e-mail com segurança.',
    ],

    'password_reset' => [
        'subject' => 'Redefina sua senha',
        'preview' => 'Redefina sua senha.',
        'greeting' => 'Olá :name,',
        'body' => 'Recebemos um pedido para redefinir sua senha. Clique no botão abaixo para criar uma nova:',
        'button' => 'Redefinir senha',
        'expiry' => 'Este link expira em 60 minutos. Se você não pediu a redefinição, pode ignorar este e-mail com segurança.',
    ],

    'post_at_risk' => [
        'subject' => '{1} :count publicação em risco em :workspace|[0,*] :count publicações em risco em :workspace',
        'title' => 'Publicações podem falhar',
        'heading' => 'Publicações podem falhar',
        'intro' => 'As contas a seguir na área de trabalho :workspace precisam ser reconectadas antes que estas publicações agendadas possam ir ao ar:',
        'posts_label' => '{1} :count publicação agendada: :times (:timezone)|[0,*] :count publicações agendadas: :times (:timezone)',
        'reconnect_cta' => 'Reconecte estas contas agora para não perder suas publicações agendadas.',
        'button' => 'Reconectar contas',
    ],

    'post_note_added' => [
        'subject' => ':author adicionou uma nota a uma publicação',
        'title' => 'Nova nota de :author',
        'heading' => 'Nova nota em uma publicação',
        'body' => ':author adicionou uma nota a uma publicação na área de trabalho :workspace.',
        'post_title' => 'Publicação',
        'post_without_text' => 'Esta publicação ainda não tem texto.',
        'button' => 'Ver nota',
    ],
    'post_approval_requested' => [
        'subject' => ':name pediu sua aprovação em um post',
        'title' => 'Um post precisa da sua aprovação',
        'preview' => ':name pediu aprovação em :workspace.',
        'heading' => 'Um post precisa da sua aprovação',
        'body' => ':name (:email) pediu aprovação no workspace :workspace.',
        'channels' => 'Canais',
        'requested_time' => 'Horário pedido',
        'next_queue_slot' => 'Próximo horário da fila',
        'as_soon_as_approved' => 'Assim que for aprovado',
        'post_without_text' => 'Este post ainda não tem texto.',
        'button' => 'Ver posts aguardando aprovação',
    ],

    'post_approved' => [
        'subject' => ':name aprovou seu post',
        'title' => 'Seu post foi aprovado',
        'preview' => ':name aprovou seu post em :workspace.',
        'heading' => 'Seu post foi aprovado',
        'body' => ':name aprovou seu post no workspace :workspace.',
        'channels' => 'Canais',
        'goes_out' => 'Vai ser publicado',
        'channel_time' => ':channel: :time',
        'publishing_now' => 'Publicando agora',
        'button' => 'Ver na fila',
    ],

    'post_rejected' => [
        'subject' => ':name não aprovou seu post',
        'title' => 'Seu post não foi aprovado',
        'preview' => ':name devolveu seu post para os rascunhos.',
        'heading' => 'Seu post não foi aprovado',
        'body' => ':name devolveu seu post do workspace :workspace para os rascunhos.',
        'channels' => 'Canais',
        'button' => 'Ver nos rascunhos',
    ],

    'post_preview' => [
        'no_text' => 'Publicação sem texto',
        'error' => 'O que aconteceu',
    ],

    'post_publish_failed' => [
        'subject' => 'Sua publicação falhou em :workspace',
        'title' => 'Sua publicação falhou',
        'preview' => 'Sua publicação falhou',
        'heading' => 'Sua publicação falhou',
        'body' => 'Não foi possível publicar seu post em :workspace.',
        'button' => 'Ver publicação',
    ],

    'post_published' => [
        'subject' => 'Sua publicação foi ao ar em :workspace',
        'title' => 'Sua publicação foi ao ar',
        'preview' => 'Sua publicação foi publicada com sucesso.',
        'heading' => 'Sua publicação foi ao ar',
        'body' => 'Sua publicação na área de trabalho :workspace foi publicada com sucesso.',
        'button' => 'Ver na rede social',
        'open_in_app' => 'Abrir no TryPost',
    ],

    'webhook_paused' => [
        'subject' => 'Webhook pausado: :endpoint',
        'title' => 'Webhook pausado após falhas repetidas',
        'preview' => 'Pausamos um webhook após 5 falhas consecutivas de entrega.',
        'heading' => 'Webhook pausado após falhas repetidas',
        'body' => 'Pausamos um webhook após 5 falhas consecutivas de entrega.',
        'next_steps' => 'Revise o endpoint e reative-o na página de detalhes do webhook.',
        'button' => 'Ver webhook',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count conta precisa ser reconectada em :workspace|[0,*] :count contas precisam ser reconectadas em :workspace',
        'title' => 'Contas Precisam ser Reconectadas',
        'heading' => 'Contas Precisam ser Reconectadas',
        'intro' => 'As seguintes contas de redes sociais no seu workspace <strong>:workspace</strong> foram desconectadas e precisam ser reconectadas:',
        'reasons_title' => 'Isso pode ter acontecido porque:',
        'reason_expired' => 'Os tokens de acesso expiraram',
        'reason_revoked' => 'Você revogou o acesso ao TryPost na plataforma',
        'reason_changed' => 'A plataforma mudou os requisitos de autenticação',
        'reconnect_cta' => 'Por favor, reconecte essas contas para continuar agendando e publicando posts.',
        'button' => 'Reconectar Contas',
    ],

    'workspace_invite' => [
        'subject' => 'Você foi convidado para o :workspace',
        'title' => 'Você foi convidado para o :workspace',
        'preview' => 'Você foi convidado para o :workspace',
        'heading' => 'Você foi convidado!',
        'intro' => 'Você foi convidado para colaborar na área de trabalho <strong>:workspace</strong>.',
        'role' => 'Você foi convidado como <strong>:role</strong>.',
        'roles' => ['admin' => 'Administrador', 'member' => 'Membro', 'needs_approval' => 'Membro (posts precisam de aprovação)'],
        'button' => 'Aceitar convite',
        'expiry' => 'Este convite expira em 7 dias.',
    ],

];
