<?php

declare(strict_types=1);

return [

    'layout' => [
        'tagline' => 'Herramienta de código abierto para programar redes sociales',
        'manage_notifications' => 'Gestionar notificaciones',
        'signoff' => 'Un saludo,',
        'team' => 'El equipo de TryPost',
    ],

    'account_disconnected' => [
        'subject' => 'Tu cuenta de :platform en :workspace necesita reconectarse',
        'title' => 'Tu cuenta de :platform necesita reconectarse',
        'preview' => 'Reconecta tu cuenta de :platform en :workspace para seguir programando publicaciones.',
        'heading' => 'Cuenta desconectada',
        'intro' => 'Tu cuenta de <strong>:platform</strong> <strong>:account</strong> se ha desconectado del espacio de trabajo <strong>:workspace</strong>.',
        'reasons_title' => 'Esto puede haber ocurrido porque:',
        'reason_expired' => 'Tu token de acceso caducó',
        'reason_revoked' => 'Revocaste el acceso a TryPost',
        'reason_error' => 'Hubo un error de autenticación',
        'reconnect_cta' => 'Reconecta tu cuenta para seguir programando y publicando.',
        'button' => 'Reconectar cuenta',
    ],

    'email_verification' => [
        'subject' => 'Verifica tu dirección de correo',
        'preview' => 'Verifica tu dirección de correo.',
        'greeting' => 'Hola :name:',
        'body' => 'Confirma tu dirección de correo haciendo clic en el botón de abajo:',
        'button' => 'Verificar correo',
        'ignore' => 'Si no creaste una cuenta, puedes ignorar este correo sin problema.',
    ],

    'password_reset' => [
        'subject' => 'Restablece tu contraseña',
        'preview' => 'Restablece tu contraseña.',
        'greeting' => 'Hola :name:',
        'body' => 'Recibimos una solicitud para restablecer tu contraseña. Haz clic en el botón para crear una nueva:',
        'button' => 'Restablecer contraseña',
        'expiry' => 'Este enlace caduca en 60 minutos. Si no solicitaste el cambio, puedes ignorar este correo sin problema.',
    ],

    'post_at_risk' => [
        'subject' => '{1} :count publicación en riesgo en :workspace|[0,*] :count publicaciones en riesgo en :workspace',
        'title' => 'Tus publicaciones podrían fallar',
        'heading' => 'Tus publicaciones podrían fallar',
        'intro' => 'Las siguientes cuentas del espacio de trabajo :workspace necesitan reconectarse antes de que estas publicaciones programadas puedan salir:',
        'posts_label' => '{1} :count publicación programada: :times (:timezone)|[0,*] :count publicaciones programadas: :times (:timezone)',
        'reconnect_cta' => 'Reconecta estas cuentas ahora para no perder tus publicaciones programadas.',
        'button' => 'Reconectar cuentas',
    ],

    'post_note_added' => [
        'subject' => ':author añadió una nota a una publicación',
        'title' => 'Nueva nota de :author',
        'heading' => 'Nueva nota en una publicación',
        'body' => ':author añadió una nota a una publicación en el espacio de trabajo :workspace.',
        'post_title' => 'Publicación',
        'post_without_text' => 'Esta publicación aún no tiene texto.',
        'button' => 'Ver nota',
    ],
    'post_approval_requested' => [
        'subject' => ':name te pidió aprobar una publicación',
        'title' => 'Una publicación necesita tu aprobación',
        'preview' => ':name pidió aprobación en :workspace.',
        'heading' => 'Una publicación necesita tu aprobación',
        'body' => ':name (:email) pidió aprobación en el workspace :workspace.',
        'channels' => 'Canales',
        'requested_time' => 'Hora solicitada',
        'next_queue_slot' => 'Próximo hueco de la cola',
        'as_soon_as_approved' => 'En cuanto se apruebe',
        'post_without_text' => 'Esta publicación aún no tiene texto.',
        'button' => 'Ver publicaciones pendientes de aprobación',
    ],

    'post_approved' => [
        'subject' => ':name aprobó tu publicación',
        'title' => 'Tu publicación fue aprobada',
        'preview' => ':name aprobó tu publicación en :workspace.',
        'heading' => 'Tu publicación fue aprobada',
        'body' => ':name aprobó tu publicación en el workspace :workspace.',
        'channels' => 'Canales',
        'goes_out' => 'Se publicará',
        'channel_time' => ':channel: :time',
        'publishing_now' => 'Publicando ahora',
        'button' => 'Ver en la cola',
    ],

    'post_rejected' => [
        'subject' => ':name no aprobó tu publicación',
        'title' => 'Tu publicación no fue aprobada',
        'preview' => ':name devolvió tu publicación a borradores.',
        'heading' => 'Tu publicación no fue aprobada',
        'body' => ':name devolvió tu publicación del workspace :workspace a borradores.',
        'channels' => 'Canales',
        'button' => 'Ver en borradores',
    ],

    'post_publish_failed' => [
        'subject' => 'Tu publicación falló en :workspace',
        'title' => 'Tu publicación falló',
        'preview' => 'Una o más plataformas no pudieron publicar.',
        'heading' => 'Tu publicación falló',
        'body' => 'Tu publicación programada en el espacio de trabajo :workspace falló en una o más plataformas.',
        'platforms_title' => 'Plataformas con error:',
        'button' => 'Ver publicación',
    ],

    'post_published' => [
        'subject' => 'Tu publicación salió en :workspace',
        'title' => 'Tu publicación salió',
        'preview' => 'Tu publicación se publicó correctamente.',
        'heading' => 'Tu publicación salió',
        'body' => 'Tu publicación en el espacio de trabajo :workspace se publicó correctamente.',
        'platforms_title' => 'Publicada en:',
        'view_post' => 'Ver publicación',
        'button' => 'Ver publicación',
    ],

    'webhook_paused' => [
        'subject' => 'Webhook pausado: :endpoint',
        'title' => 'Webhook pausado tras fallos repetidos',
        'preview' => 'Pausamos un webhook tras 5 fallos consecutivos de entrega.',
        'heading' => 'Webhook pausado tras fallos repetidos',
        'body' => 'Pausamos el webhook en :endpoint tras 5 fallos consecutivos de entrega. Revisa el endpoint y actívalo de nuevo en la página de detalles del webhook.',
        'button' => 'Ver webhook',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count cuenta necesita ser reconectada en :workspace|[0,*] :count cuentas necesitan ser reconectadas en :workspace',
        'title' => 'Cuentas necesitan reconexión',
        'heading' => 'Cuentas necesitan reconexión',
        'intro' => 'Las siguientes cuentas sociales en tu workspace <strong>:workspace</strong> se han desconectado y necesitan ser reconectadas:',
        'reasons_title' => 'Esto puede haber ocurrido porque:',
        'reason_expired' => 'Los tokens de acceso expiraron',
        'reason_revoked' => 'Revocaste el acceso a TryPost en la plataforma',
        'reason_changed' => 'La plataforma cambió sus requisitos de autenticación',
        'reconnect_cta' => 'Reconecta estas cuentas para seguir programando y publicando posts.',
        'button' => 'Reconectar cuentas',
    ],

    'workspace_invite' => [
        'subject' => 'Te han invitado a unirte a :account',
        'title' => 'Te han invitado a unirte a :account',
        'preview' => 'Te han invitado a unirte a :account',
        'heading' => '¡Te han invitado!',
        'intro' => 'Te han invitado a colaborar en el espacio de trabajo <strong>:account</strong>.',
        'role' => 'Te han invitado como <strong>:role</strong>.',
        'roles' => ['admin' => 'Administrador', 'member' => 'Miembro', 'needs_approval' => 'Miembro (sus publicaciones necesitan aprobación)'],
        'button' => 'Aceptar invitación',
        'expiry' => 'Esta invitación caduca en 7 días.',
    ],

];
