<?php
/**
 * Configuración de almacenamiento — Google Drive via OAuth 2.0.
 */

function getDriveConfig(): array
{
    return [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
        'redirect_uri' => env('GOOGLE_REDIRECT_URI', ''),
        'folder_id' => env('GOOGLE_DRIVE_FOLDER_ID', ''),
    ];
}
