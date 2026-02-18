<?php
/**
 * Google Drive Service — OAuth 2.0 + Drive API v3.
 * Usa API REST directa (sin SDK pesado).
 */

class GoogleDriveService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private ?string $accessToken = null;
    private ?string $refreshToken = null;

    public function __construct()
    {
        $this->clientId = env('GOOGLE_CLIENT_ID', '');
        $this->clientSecret = env('GOOGLE_CLIENT_SECRET', '');
        $this->redirectUri = env('GOOGLE_REDIRECT_URI', '');
    }

    // ========================================
    // OAuth 2.0
    // ========================================

    /**
     * URL para iniciar el flujo OAuth (redirigir al usuario aquí).
     */
    public function getAuthUrl(): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive',
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Intercambia el code de OAuth por tokens.
     */
    public function exchangeCode(string $code): array
    {
        $response = $this->httpPost('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]);
        return json_decode($response, true) ?: [];
    }

    /**
     * Renueva el access_token usando el refresh_token.
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        $response = $this->httpPost('https://oauth2.googleapis.com/token', [
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type' => 'refresh_token',
        ]);
        return json_decode($response, true) ?: [];
    }

    /**
     * Configura los tokens para las llamadas API.
     */
    public function setTokens(string $accessToken, string $refreshToken): void
    {
        $this->accessToken = $accessToken;
        $this->refreshToken = $refreshToken;
    }

    /**
     * Obtiene un access_token válido, renovando si es necesario.
     */
    public function getValidToken(PDO $db): ?string
    {
        $row = $db->query("SELECT * FROM google_tokens ORDER BY id DESC LIMIT 1")->fetch();
        if (!$row)
            return null;

        $this->refreshToken = $row['refresh_token'];

        // Si el token expira en menos de 5 minutos, renovar
        if (strtotime($row['expires_at']) < time() + 300) {
            $data = $this->refreshAccessToken($row['refresh_token']);
            if (!empty($data['access_token'])) {
                $expiresAt = date('Y-m-d H:i:s', time() + ($data['expires_in'] ?? 3600));
                $stmt = $db->prepare("UPDATE google_tokens SET access_token = ?, expires_at = ? WHERE id = ?");
                $stmt->execute([$data['access_token'], $expiresAt, $row['id']]);
                $this->accessToken = $data['access_token'];
            }
        } else {
            $this->accessToken = $row['access_token'];
        }

        return $this->accessToken;
    }

    // ========================================
    // Drive API
    // ========================================

    /**
     * Lista archivos en una carpeta de Drive (todas las páginas).
     */
    public function listFiles(string $folderId, int $pageSize = 100, string $pageToken = ''): array
    {
        $allFiles = [];
        $token = $pageToken;

        do {
            $query = "'{$folderId}' in parents and trashed = false";
            $params = [
                'q' => $query,
                'fields' => 'nextPageToken,files(id,name,mimeType,size,thumbnailLink,webViewLink,webContentLink,createdTime)',
                'pageSize' => $pageSize,
                'orderBy' => 'name',
            ];
            if ($token)
                $params['pageToken'] = $token;

            $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query($params);
            $response = $this->httpGet($url);
            $data = json_decode($response, true) ?: [];

            $files = $data['files'] ?? [];
            $allFiles = array_merge($allFiles, $files);
            $token = $data['nextPageToken'] ?? '';
        } while (!empty($token));

        return ['files' => $allFiles];
    }

    /**
     * Busca archivos cuyo nombre contenga el SKU.
     * Primero busca en la carpeta raíz, luego en subcarpetas.
     */
    public function findBySku(string $folderId, string $sku): array
    {
        $skuEscaped = str_replace("'", "\\'", $sku);

        // 1) Buscar directamente en la carpeta raíz
        $query = "'{$folderId}' in parents and name contains '{$skuEscaped}' and trashed = false";
        $params = [
            'q' => $query,
            'fields' => 'files(id,name,mimeType,size,thumbnailLink,webViewLink,webContentLink)',
            'pageSize' => 50,
        ];

        $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query($params);
        $response = $this->httpGet($url);
        $data = json_decode($response, true) ?: [];
        $files = $data['files'] ?? [];

        if (!empty($files)) {
            return $files;
        }

        // 2) Si no hay resultados, buscar subcarpetas y luego dentro de ellas
        $subQuery = "'{$folderId}' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        $subParams = [
            'q' => $subQuery,
            'fields' => 'files(id,name)',
            'pageSize' => 50,
        ];
        $subUrl = 'https://www.googleapis.com/drive/v3/files?' . http_build_query($subParams);
        $subResp = $this->httpGet($subUrl);
        $subData = json_decode($subResp, true) ?: [];
        $subfolders = $subData['files'] ?? [];

        foreach ($subfolders as $sub) {
            $subFileQuery = "'{$sub['id']}' in parents and name contains '{$skuEscaped}' and trashed = false";
            $subFileParams = [
                'q' => $subFileQuery,
                'fields' => 'files(id,name,mimeType,size,thumbnailLink,webViewLink,webContentLink)',
                'pageSize' => 50,
            ];
            $subFileUrl = 'https://www.googleapis.com/drive/v3/files?' . http_build_query($subFileParams);
            $subFileResp = $this->httpGet($subFileUrl);
            $subFileData = json_decode($subFileResp, true) ?: [];
            $subFiles = $subFileData['files'] ?? [];

            if (!empty($subFiles)) {
                $files = array_merge($files, $subFiles);
            }
        }

        return $files;
    }

    /**
     * Sube un archivo a Drive.
     */
    public function uploadFile(string $folderId, string $filename, string $filePath, string $mimeType): ?array
    {
        $boundary = 'viewfinder_boundary_' . uniqid();
        $fileContent = file_get_contents($filePath);

        $metadata = json_encode([
            'name' => $filename,
            'parents' => [$folderId],
        ]);

        $body = "--{$boundary}\r\n"
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . $metadata . "\r\n"
            . "--{$boundary}\r\n"
            . "Content-Type: {$mimeType}\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . base64_encode($fileContent) . "\r\n"
            . "--{$boundary}--";

        $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink,webContentLink';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->accessToken}",
                "Content-Type: multipart/related; boundary={$boundary}",
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true) ?: null;
    }

    /**
     * Elimina un archivo de Drive.
     */
    public function deleteFile(string $fileId): bool
    {
        $url = "https://www.googleapis.com/drive/v3/files/{$fileId}";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->accessToken}",
            ],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 204 || $code === 200;
    }

    /**
     * Obtener URL pública de un archivo (con permisos).
     */
    public function makePublic(string $fileId): bool
    {
        $url = "https://www.googleapis.com/drive/v3/files/{$fileId}/permissions";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['role' => 'reader', 'type' => 'anyone']),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->accessToken}",
                "Content-Type: application/json",
            ],
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }

    /**
     * Obtiene thumbnail/preview URL de un archivo.
     */
    public function getThumbnailUrl(string $fileId): string
    {
        return "https://drive.google.com/thumbnail?id={$fileId}&sz=w400";
    }

    /**
     * Crea una subcarpeta dentro de una carpeta.
     */
    public function createFolder(string $parentId, string $name): ?array
    {
        $url = 'https://www.googleapis.com/drive/v3/files?fields=id,name';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'name' => $name,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => [$parentId],
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->accessToken}",
                "Content-Type: application/json",
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode($response, true) ?: null;
    }

    // ========================================
    // HTTP helpers
    // ========================================

    private function httpGet(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$this->accessToken}",
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response ?: '';
    }

    private function httpPost(string $url, array $data): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response ?: '';
    }
}
