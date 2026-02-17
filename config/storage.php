<?php
/**
 * Configuración de almacenamiento (Cloudflare R2 / S3 compatible).
 * Se usa en Fase 2 para subida de media.
 */

function getStorageConfig(): array
{
    return [
        'endpoint' => getenv('R2_ENDPOINT') ?: '',
        'access_key' => getenv('R2_ACCESS_KEY') ?: '',
        'secret_key' => getenv('R2_SECRET_KEY') ?: '',
        'bucket' => getenv('R2_BUCKET') ?: 'visor-kino-media',
        'public_url' => getenv('R2_PUBLIC_URL') ?: '',
    ];
}
