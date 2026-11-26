<?php
/**
 * Envío automático por WhatsApp Cloud API (Meta). OPCIONAL: solo si hay WHATSAPP_TOKEN y WHATSAPP_PHONE_ID en el entorno.
 * Sin ellos no hace nada y la tienda usa enlaces wa.me con el texto ya escrito (el cliente/vendedor toca «Enviar»).
 * Texto libre solo llega si el destinatario escribió al número en las últimas 24 h; fuera de esa ventana Meta pide una
 * plantilla aprobada (WHATSAPP_TEMPLATE, con 1 variable: el resumen). Nunca lanza: devuelve true/false.
 */
class Whatsapp
{
    public static function enabled(): bool { return env('WHATSAPP_TOKEN') && env('WHATSAPP_PHONE_ID'); }

    public static function send(string $to, string $text): bool
    {
        if (!self::enabled()) return false;
        $to = preg_replace('/\D+/', '', $to) ?: '';
        if (strlen($to) === 10) $to = '57' . $to; // celular colombiano sin indicativo
        if (strlen($to) < 11) return false;
        $tpl = (string) env('WHATSAPP_TEMPLATE');
        $body = ['messaging_product' => 'whatsapp', 'to' => $to];
        if ($tpl !== '') {
            $body['type'] = 'template';
            $body['template'] = ['name' => $tpl, 'language' => ['code' => 'es'], 'components' => [['type' => 'body', 'parameters' => [['type' => 'text', 'text' => mb_substr(preg_replace('/\s+/', ' ', $text), 0, 900, 'UTF-8')]]]]];
        } else {
            $body['type'] = 'text';
            $body['text'] = ['body' => mb_substr($text, 0, 3500, 'UTF-8'), 'preview_url' => true];
        }
        $ch = curl_init('https://graph.facebook.com/v21.0/' . rawurlencode((string) env('WHATSAPP_PHONE_ID')) . '/messages');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . env('WHATSAPP_TOKEN'), 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
        curl_exec($ch);
        $ok = curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);
        return $ok;
    }
}
