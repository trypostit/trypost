<?php

declare(strict_types=1);

return [

    'layout' => [
        'tagline' => 'Ferramenta open-source de agendamento para redes sociais',
        'manage_notifications' => 'Gerenciar notificações',
        'signoff' => 'Atenciosamente,',
        'team' => 'A equipe TryPost',
    ],

    'account_disconnected' => [
        'subject' => 'Sua conta do :platform em :workspace precisa ser reconectada',
        'title' => 'Sua conta do :platform precisa ser reconectada',
        'preview' => 'Reconecte sua conta do :platform em :workspace para continuar agendando publicações.',
        'heading' => 'Conta desconectada',
        'intro' => 'Sua conta do <strong>:platform</strong> <strong>:account</strong> foi desconectada da área de trabalho <strong>:workspace</strong>.',
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

    'mentioned_in_comment' => [
        'subject' => ':name mencionou você no TryPost',
        'title' => ':name mencionou você',
        'intro' => ':name mencionou você num comentário.',
        'button' => 'Ver comentário',
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
        'posts_label' => '{1} :count publicação agendada: :times UTC|[0,*] :count publicações agendadas: :times UTC',
        'reconnect_cta' => 'Reconecte estas contas agora para não perder suas publicações agendadas.',
        'button' => 'Reconectar contas',
    ],

    'post_publish_failed' => [
        'subject' => 'Sua publicação falhou em :workspace',
        'title' => 'Sua publicação falhou',
        'preview' => 'Uma ou mais plataformas falharam ao publicar.',
        'heading' => 'Sua publicação falhou',
        'body' => 'Sua publicação agendada na área de trabalho :workspace falhou em uma ou mais plataformas.',
        'platforms_title' => 'Plataformas com falha:',
        'button' => 'Ver publicação',
    ],

    'post_published' => [
        'subject' => 'Sua publicação foi ao ar em :workspace',
        'title' => 'Sua publicação foi ao ar',
        'preview' => 'Sua publicação foi publicada com sucesso.',
        'heading' => 'Sua publicação foi ao ar',
        'body' => 'Sua publicação na área de trabalho :workspace foi publicada com sucesso.',
        'platforms_title' => 'Publicada em:',
        'view_post' => 'Ver publicação',
        'button' => 'Ver publicação',
    ],

    'webhook_paused' => [
        'subject' => 'Webhook pausado: :endpoint',
        'title' => 'Webhook pausado após falhas repetidas',
        'preview' => 'Pausamos um webhook após 5 falhas consecutivas de entrega.',
        'heading' => 'Webhook pausado após falhas repetidas',
        'body' => 'Pausamos o webhook em :endpoint após 5 falhas consecutivas de entrega. Revise o endpoint e reative-o na página de detalhes do webhook.',
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
        'subject' => 'Você foi convidado para o :account',
        'title' => 'Você foi convidado para o :account',
        'preview' => 'Você foi convidado para o :account',
        'heading' => 'Você foi convidado!',
        'intro' => 'Você foi convidado para colaborar na área de trabalho <strong>:account</strong>.',
        'role' => 'Você foi convidado como <strong>:role</strong>.',
        'button' => 'Aceitar convite',
        'expiry' => 'Este convite expira em 7 dias.',
    ],

];
