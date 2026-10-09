<?php

declare(strict_types=1);

return [

    'layout' => [
        'tagline' => 'Envoyé par l’équipe :brand',
        'manage_notifications' => 'Gérer les notifications',
        'signoff' => 'Cordialement,',
        'team' => 'L\'équipe TryPost',
    ],

    'account_disconnected' => [
        'subject' => 'Votre compte :platform dans :workspace doit être reconnecté',
        'title' => 'Votre compte :platform doit être reconnecté',
        'preview' => 'Reconnectez votre compte :platform dans :workspace pour continuer à planifier vos publications.',
        'heading' => 'Compte déconnecté',
        'intro' => 'Le compte ci-dessous a été déconnecté de l’espace de travail :workspace.',
        'reasons_title' => 'Cela peut être dû à :',
        'reason_expired' => 'Votre jeton d’accès a expiré',
        'reason_revoked' => 'Vous avez révoqué l’accès de TryPost',
        'reason_error' => 'Une erreur d’authentification est survenue',
        'reconnect_cta' => 'Reconnectez votre compte pour continuer à planifier et publier.',
        'button' => 'Reconnecter le compte',
    ],

    'email_verification' => [
        'subject' => 'Vérifiez votre adresse e-mail',
        'preview' => 'Veuillez vérifier votre adresse e-mail.',
        'greeting' => 'Bonjour :name,',
        'body' => 'Confirmez votre adresse e-mail en cliquant sur le bouton ci-dessous :',
        'button' => 'Vérifier mon e-mail',
        'ignore' => 'Si vous n’avez pas créé de compte, vous pouvez ignorer cet e-mail.',
    ],

    'password_reset' => [
        'subject' => 'Réinitialisez votre mot de passe',
        'preview' => 'Réinitialisez votre mot de passe.',
        'greeting' => 'Bonjour :name,',
        'body' => 'Nous avons reçu une demande de réinitialisation de votre mot de passe. Cliquez sur le bouton pour en créer un nouveau :',
        'button' => 'Réinitialiser le mot de passe',
        'expiry' => 'Ce lien expire dans 60 minutes. Si vous n’êtes pas à l’origine de cette demande, vous pouvez ignorer cet e-mail.',
    ],

    'post_at_risk' => [
        'subject' => '{1} :count publication menacée dans :workspace|[0,*] :count publications menacées dans :workspace',
        'title' => 'Vos publications risquent d’échouer',
        'heading' => 'Vos publications risquent d’échouer',
        'intro' => 'Les comptes suivants de l’espace de travail :workspace doivent être reconnectés avant que ces publications planifiées puissent partir :',
        'posts_label' => '{1} :count publication planifiée : :times (:timezone)|[0,*] :count publications planifiées : :times (:timezone)',
        'reconnect_cta' => 'Reconnectez ces comptes dès maintenant pour ne pas manquer vos publications planifiées.',
        'button' => 'Reconnecter les comptes',
    ],

    'post_note_added' => [
        'subject' => ':author a ajouté une note à une publication',
        'title' => 'Nouvelle note de :author',
        'heading' => 'Nouvelle note sur une publication',
        'body' => ':author a ajouté une note à une publication dans l\'espace de travail :workspace.',
        'post_title' => 'Publication',
        'post_without_text' => 'Cette publication n\'a pas encore de texte.',
        'button' => 'Voir la note',
    ],
    'post_approval_requested' => [
        'subject' => ':name vous demande d\'approuver une publication',
        'title' => 'Une publication attend votre approbation',
        'preview' => ':name demande une approbation dans :workspace.',
        'heading' => 'Une publication attend votre approbation',
        'body' => ':name (:email) demande une approbation dans l\'espace de travail :workspace.',
        'channel' => 'Canal',
        'requested_time' => 'Heure demandée',
        'next_queue_slot' => 'Prochain créneau de la file d\'attente',
        'as_soon_as_approved' => 'Dès son approbation',
        'post_without_text' => 'Cette publication n\'a pas encore de texte.',
        'button' => 'Voir les publications en attente d\'approbation',
    ],

    'post_approved' => [
        'subject' => ':name a approuvé votre publication',
        'title' => 'Votre publication a été approuvée',
        'preview' => ':name a approuvé votre publication dans :workspace.',
        'heading' => 'Votre publication a été approuvée',
        'body' => ':name a approuvé votre publication dans l\'espace de travail :workspace.',
        'channel' => 'Canal',
        'goes_out' => 'Publication prévue',
        'channel_time' => ':channel : :time',
        'publishing_now' => 'Publication en cours',
        'button' => 'Voir dans la file d\'attente',
    ],

    'post_rejected' => [
        'subject' => ':name n\'a pas approuvé votre publication',
        'title' => 'Votre publication n\'a pas été approuvée',
        'preview' => ':name a remis votre publication dans les brouillons.',
        'heading' => 'Votre publication n\'a pas été approuvée',
        'body' => ':name a remis votre publication de l\'espace de travail :workspace dans les brouillons.',
        'channel' => 'Canal',
        'button' => 'Voir dans les brouillons',
    ],

    'post_preview' => [
        'no_text' => 'Publication sans texte',
        'error' => 'Ce qui s’est passé',
    ],

    'post_publish_failed' => [
        'subject' => 'Votre publication a échoué dans :workspace',
        'title' => 'Votre publication a échoué',
        'preview' => 'Votre publication a échoué',
        'heading' => 'Votre publication a échoué',
        'body' => 'Nous n’avons pas pu publier votre post dans :workspace.',
        'button' => 'Voir la publication',
    ],

    'post_published' => [
        'subject' => 'Votre publication est en ligne dans :workspace',
        'title' => 'Votre publication est en ligne',
        'preview' => 'Votre publication a bien été publiée.',
        'heading' => 'Votre publication est en ligne',
        'body' => 'Votre publication dans l’espace de travail :workspace a bien été publiée.',
        'button' => 'Voir sur le réseau',
        'open_in_app' => 'Ouvrir dans TryPost',
    ],

    'webhook_paused' => [
        'subject' => 'Webhook en pause : :endpoint',
        'title' => 'Webhook mis en pause après des échecs répétés',
        'preview' => 'Nous avons mis un webhook en pause après 5 échecs de livraison consécutifs.',
        'heading' => 'Webhook mis en pause après des échecs répétés',
        'body' => 'Nous avons mis un webhook en pause après 5 échecs de livraison consécutifs.',
        'next_steps' => 'Vérifiez l\'endpoint et réactivez-le depuis la page de détails du webhook.',
        'button' => 'Voir le webhook',
    ],

    'workspace_connections_disconnected' => [
        'subject' => '{1} :count compte doit être reconnecté dans :workspace|[0,*] :count comptes doivent être reconnectés dans :workspace',
        'title' => 'Des comptes doivent être reconnectés',
        'heading' => 'Des comptes doivent être reconnectés',
        'intro' => 'Les comptes sociaux suivants de votre espace de travail <strong>:workspace</strong> ont été déconnectés et doivent être reconnectés :',
        'reasons_title' => 'Cela peut être dû à l\'une des raisons suivantes :',
        'reason_expired' => 'Les jetons d\'accès ont expiré',
        'reason_revoked' => 'Vous avez révoqué l\'accès de TryPost sur la plateforme',
        'reason_changed' => 'La plateforme a modifié ses exigences d\'authentification',
        'reconnect_cta' => 'Veuillez reconnecter ces comptes pour continuer à programmer et publier vos publications.',
        'button' => 'Reconnecter les comptes',
    ],

    'workspace_invite' => [
        'subject' => 'Vous êtes invité à rejoindre :workspace',
        'title' => 'Vous êtes invité à rejoindre :workspace',
        'preview' => 'Vous êtes invité à rejoindre :workspace',
        'heading' => 'Vous êtes invité !',
        'intro' => 'Vous êtes invité à collaborer sur l’espace de travail <strong>:workspace</strong>.',
        'role' => 'Vous êtes invité en tant que <strong>:role</strong>.',
        'roles' => ['admin' => 'Administrateur', 'member' => 'Membre', 'needs_approval' => 'Membre (ses publications doivent être approuvées)'],
        'button' => 'Accepter l’invitation',
        'expiry' => 'Cette invitation expire dans 7 jours.',
    ],

];
