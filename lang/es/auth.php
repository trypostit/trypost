<?php

return [

    'password_strength' => [
        'length' => 'Al menos 12 caracteres',
        'case' => 'Mayúsculas y minúsculas',
        'number' => 'Al menos un número',
        'symbol' => 'Al menos un símbolo',
        'weak' => 'Débil',
        'fair' => 'Aceptable',
        'good' => 'Buena',
        'strong' => 'Fuerte',
    ],
    'failed' => 'Estas credenciales no coinciden con nuestros registros.',
    'password' => 'La contraseña proporcionada es incorrecta.',
    'throttle' => 'Demasiados intentos de inicio de sesión. Inténtalo de nuevo en :seconds segundos.',

    'flash' => [
        'welcome' => '¡Bienvenido a TryPost!',
        'welcome_trial' => '¡Bienvenido a TryPost! Tu prueba ha comenzado.',
    ],

    'legal' => 'Al continuar, aceptas nuestros <a href="https://trypost.it/terms" target="_blank">Términos de Servicio</a> y <a href="https://trypost.it/privacy" target="_blank">Política de Privacidad</a>.',

    'slides' => [
        'calendar' => [
            'title' => 'Calendario Visual',
            'description' => 'Planifica y programa tu contenido con un calendario intuitivo de arrastrar y soltar en todas tus cuentas sociales.',
        ],
        'scheduling' => [
            'title' => 'Programación Inteligente',
            'description' => 'Programa posts en LinkedIn, X, Instagram, TikTok, YouTube y más — todo desde un solo lugar.',
        ],
        'media' => [
            'title' => 'Contenido Multimedia',
            'description' => 'Publica imágenes, carruseles, historias y reels. Cada plataforma recibe el formato correcto automáticamente.',
        ],
        'video' => [
            'title' => 'Publicación de Video',
            'description' => 'Sube videos una vez y publícalos en TikTok, YouTube Shorts, Instagram Reels y Facebook Reels.',
        ],
        'team' => [
            'title' => 'Workspaces en Equipo',
            'description' => 'Invita a tu equipo, asigna roles y gestiona múltiples marcas en workspaces separados.',
        ],
        'signatures' => [
            'title' => 'Firmas',
            'description' => 'Guarda firmas reutilizables (hashtags, links, despedidas) y añádelas a tus posts con un clic.',
        ],
    ],

    'or_continue_with' => 'O continuar con',
    'or_continue_with_email' => 'O continúa con correo',
    'google_login' => 'Iniciar sesión con Google',
    'google_signup' => 'Registrarse con Google',
    'github_login' => 'Iniciar sesión con GitHub',
    'github_signup' => 'Registrarse con GitHub',
    'github_email_unavailable' => 'No fue posible obtener tu correo de GitHub. Haz tu correo público en GitHub o concede el permiso de correo y vuelve a intentar.',
    'social_email_unverified' => 'No pudimos confirmar tu correo con :provider. Verifícalo allí o entra con correo y contraseña.',

    'signup_success' => [
        'page_title' => 'Bienvenido',
        'title' => 'Configurando tu cuenta',
        'description' => 'Esto suele tardar solo unos segundos...',
    ],

    'login' => [
        'title' => 'Inicia sesión en tu cuenta',
        'description' => 'Introduce tu correo y contraseña para iniciar sesión',
        'page_title' => 'Iniciar sesión',
        'email' => 'Correo electrónico',
        'password' => 'Contraseña',
        'forgot_password' => '¿Olvidaste tu contraseña?',
        'remember_me' => 'Recuérdame',
        'submit' => 'Iniciar sesión',
        'no_account' => '¿No tienes una cuenta?',
        'sign_up' => 'Regístrate',
    ],

    'register' => [
        'title' => 'Todo tu calendario social en un solo lugar',
        'description' => 'Crea tu cuenta y empieza a programar publicaciones en todas las redes.',
        'page_title' => 'Registro',
        'signup_with_email' => 'Registrarse con correo',
        'name' => 'Nombre',
        'name_placeholder' => 'Nombre completo',
        'email' => 'Correo electrónico',
        'password' => 'Contraseña',
        'show_password' => 'Mostrar contraseña',
        'hide_password' => 'Ocultar contraseña',
        'submit' => 'Crear cuenta',
        'has_account' => '¿Ya tienes una cuenta?',
        'log_in' => 'Iniciar sesión',
        'disposable_email' => 'Usa un correo permanente. No se aceptan direcciones temporales.',
        'quota_reached' => 'No pudimos crear la cuenta ahora. Inténtalo de nuevo más tarde.',
    ],

    'forgot_password' => [
        'title' => 'Olvidé mi contraseña',
        'description' => 'Introduce tu correo para recibir un enlace de restablecimiento',
        'page_title' => 'Olvidé mi contraseña',
        'email' => 'Correo electrónico',
        'submit' => 'Enviar enlace de restablecimiento',
        'return_to' => 'O vuelve a',
        'log_in' => 'iniciar sesión',
    ],

    'reset_password' => [
        'title' => 'Restablecer contraseña',
        'description' => 'Introduce tu nueva contraseña',
        'page_title' => 'Restablecer contraseña',
        'email' => 'Correo electrónico',
        'password' => 'Contraseña',
        'confirm_password' => 'Confirmar contraseña',
        'confirm_placeholder' => 'Confirmar contraseña',
        'submit' => 'Restablecer contraseña',
    ],

    'verify_email' => [
        'title' => 'Verificar correo',
        'description' => 'Verifica tu correo electrónico haciendo clic en el enlace que acabamos de enviarte.',
        'page_title' => 'Verificación de correo',
        'link_sent' => 'Se ha enviado un nuevo enlace de verificación al correo electrónico proporcionado durante el registro.',
        'resend' => 'Reenviar correo de verificación',
        'log_out' => 'Cerrar sesión',
        'sent_to' => 'Correo enviado a',
        'instructions' => 'Revisa tu bandeja de entrada (y el spam, por si acaso). Necesitas confirmar tu correo para continuar.',
        'wrong_email' => '¿Correo equivocado? Corregir',
        'new_email_label' => 'Nuevo correo',
        'update_email' => 'Guardar y reenviar',
        'cancel' => 'Cancelar',
    ],

    'accept_invite' => [
        'page_title' => 'Aceptar invitación',
        'title' => '¡Has sido invitado!',
        'description' => 'Has sido invitado a unirte al workspace :workspace.',
        'workspace' => 'Workspace',
        'your_role' => 'Tu rol',
        'email' => 'Correo electrónico',
        'accept' => 'Aceptar invitación',
        'decline' => 'Rechazar invitación',
        'login_prompt' => 'Inicia sesión o crea una cuenta para aceptar esta invitación.',
        'log_in' => 'Iniciar sesión',
        'create_account' => 'Crear cuenta',
    ],
];
