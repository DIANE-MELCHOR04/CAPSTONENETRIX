<?php
declare(strict_types=1);

require_once __DIR__ . '/settings.php';

function ai_config(): array
{
    $config = [
        'gemini_api_key' => '',
        'gemini_model' => 'gemini-2.0-flash',
    ];

    $localPath = __DIR__ . '/../config/ai.local.php';
    if (is_file($localPath)) {
        $local = require $localPath;
        if (is_array($local)) {
            $config = array_merge($config, $local);
        }
    }

    return $config;
}

function gemini_is_configured(): bool
{
    $config = ai_config();

    return trim((string) ($config['gemini_api_key'] ?? '')) !== '';
}

function normalize_gemini_model(string $model): string
{
    $model = trim($model);
    if ($model === '') {
        return 'gemini-2.0-flash';
    }

    return preg_replace('/^models\//', '', $model) ?: 'gemini-2.0-flash';
}

function build_gemini_recommendation_prompt(array $context): string
{
    $compact = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

    return <<<PROMPT
You are an inventory planning analyst for a construction materials control system.
Use only the JSON context below.
Return one JSON object with these exact keys: title, urgency, recommendation, reason, suggested_action.
Urgency must be one of: critical, high, medium, stable.
Do not invent suppliers, prices, approvals, or delivery dates.
Keep the recommendation practical for warehouse, procurement, and site teams.

Inventory context:
{$compact}
PROMPT;
}

function parse_gemini_recommendation(string $text): array
{
    $clean = trim($text);
    $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean) ?? $clean;
    $clean = preg_replace('/\s*```$/', '', $clean) ?? $clean;

    $decoded = json_decode($clean, true);
    if (!is_array($decoded) && preg_match('/\{.*\}/s', $clean, $match)) {
        $decoded = json_decode($match[0], true);
    }

    if (!is_array($decoded)) {
        throw new RuntimeException('Gemini returned text that was not valid recommendation JSON.');
    }

    $urgency = strtolower(trim((string) ($decoded['urgency'] ?? 'medium')));
    if (!in_array($urgency, ['critical', 'high', 'medium', 'stable'], true)) {
        $urgency = 'medium';
    }

    return [
        'title' => trim((string) ($decoded['title'] ?? 'Review material forecast')),
        'urgency' => $urgency,
        'recommendation' => trim((string) ($decoded['recommendation'] ?? 'Review the forecast result and confirm replenishment needs.')),
        'reason' => trim((string) ($decoded['reason'] ?? 'Forecast conditions require planner review.')),
        'suggested_action' => trim((string) ($decoded['suggested_action'] ?? 'Check stock, demand, and open procurement records.')),
        'source' => 'gemini',
    ];
}

function gemini_fallback_recommendation(array $context, string $message = ''): array
{
    $material = $context['material'] ?? [];
    $forecast = $context['forecast'] ?? [];
    $inventory = $context['inventory'] ?? [];
    $urgency = (string) ($forecast['risk_level'] ?? 'medium');
    if (!in_array($urgency, ['critical', 'high', 'medium', 'stable'], true)) {
        $urgency = 'medium';
    }

    $name = (string) ($material['name'] ?? 'Material');
    $unit = (string) ($material['unit'] ?? 'unit');
    $recommended = rtrim(rtrim(number_format((float) ($forecast['recommended_order_qty'] ?? 0), 2, '.', ','), '0'), '.');
    $stock = rtrim(rtrim(number_format((float) ($inventory['available_stock'] ?? 0), 2, '.', ','), '0'), '.');

    return [
        'title' => $urgency === 'stable' ? "Monitor {$name}" : "Review replenishment for {$name}",
        'urgency' => $urgency,
        'recommendation' => "Prepare {$recommended} {$unit} for replenishment review based on the latest forecast run.",
        'reason' => "Available stock is {$stock} {$unit} and the forecast risk is {$urgency}.",
        'suggested_action' => $urgency === 'stable'
            ? 'Keep the item under routine monitoring.'
            : 'Check open purchase orders, validate site demand, and create the procurement request after review.',
        'source' => 'rules',
        'fallback_message' => $message,
    ];
}

function gemini_generate_recommendation(array $context, ?string $modelOverride = null, bool $enabled = true): array
{
    $config = ai_config();
    $apiKey = trim((string) ($config['gemini_api_key'] ?? ''));
    $model = normalize_gemini_model($modelOverride ?? (string) ($config['gemini_model'] ?? 'gemini-2.0-flash'));
    $prompt = build_gemini_recommendation_prompt($context);
    $promptHash = hash('sha256', $prompt);

    if (!$enabled) {
        $fallback = gemini_fallback_recommendation($context, 'AI assistance is set to rules only.');
        $fallback['model_name'] = null;
        $fallback['prompt_hash'] = $promptHash;
        $fallback['raw_response_json'] = null;
        return $fallback;
    }

    if ($apiKey === '') {
        $fallback = gemini_fallback_recommendation($context, 'Gemini API key is not configured.');
        $fallback['model_name'] = null;
        $fallback['prompt_hash'] = $promptHash;
        $fallback['raw_response_json'] = null;
        return $fallback;
    }

    if (!function_exists('curl_init')) {
        $fallback = gemini_fallback_recommendation($context, 'PHP cURL extension is not enabled.');
        $fallback['model_name'] = $model;
        $fallback['prompt_hash'] = $promptHash;
        $fallback['raw_response_json'] = null;
        return $fallback;
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':generateContent';
    $payload = [
        'contents' => [
            ['parts' => [['text' => $prompt]]],
        ],
        'generationConfig' => [
            'temperature' => 0.2,
            'responseMimeType' => 'application/json',
        ],
    ];

    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
    ]);

    $body = curl_exec($curl);
    $curlError = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);

    if ($body === false || $status < 200 || $status >= 300) {
        $fallback = gemini_fallback_recommendation($context, $curlError !== '' ? $curlError : "Gemini HTTP status {$status}.");
        $fallback['model_name'] = $model;
        $fallback['prompt_hash'] = $promptHash;
        $fallback['raw_response_json'] = is_string($body) ? $body : null;
        return $fallback;
    }

    $decoded = json_decode((string) $body, true);
    $text = (string) ($decoded['candidates'][0]['content']['parts'][0]['text'] ?? '');

    try {
        $recommendation = parse_gemini_recommendation($text);
    } catch (Throwable $error) {
        $recommendation = gemini_fallback_recommendation($context, $error->getMessage());
    }

    $recommendation['model_name'] = $model;
    $recommendation['prompt_hash'] = $promptHash;
    $recommendation['raw_response_json'] = (string) $body;

    return $recommendation;
}

function recommendation_context_from_forecast_item(array $item): array
{
    return [
        'material' => [
            'id' => (int) $item['material_id'],
            'code' => (string) $item['material_code'],
            'name' => (string) $item['material_name'],
            'unit' => (string) $item['unit_name'],
            'supplier' => (string) ($item['supplier_name'] ?? ''),
            'lead_time_days' => (int) ($item['lead_time_days'] ?? 0),
        ],
        'inventory' => [
            'available_stock' => (float) $item['available_stock'],
            'reorder_point' => (float) $item['reorder_point'],
            'safety_stock' => (float) $item['safety_stock'],
        ],
        'forecast' => [
            'forecast_item_id' => (int) $item['id'],
            'horizon_days' => isset($item['horizon_days']) ? (int) $item['horizon_days'] : 30,
            'forecast_qty' => (float) $item['forecast_qty'],
            'average_daily_demand' => (float) $item['average_daily_demand'],
            'projected_stockout_date' => $item['projected_stockout_date'],
            'recommended_order_qty' => (float) $item['recommended_order_qty'],
            'confidence' => (string) $item['confidence'],
            'risk_level' => (string) $item['risk_level'],
            'method' => (string) $item['method'],
        ],
    ];
}

function save_ai_recommendation(PDO $pdo, array $payload): int
{
    $stmt = $pdo->prepare(
        "INSERT INTO ai_recommendations
            (forecast_run_id, forecast_item_id, material_id, title, urgency, recommendation, reason,
             suggested_action, source, status, model_name, prompt_hash, raw_response_json, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', ?, ?, ?, ?)"
    );
    $stmt->execute([
        (int) $payload['forecast_run_id'],
        isset($payload['forecast_item_id']) ? (int) $payload['forecast_item_id'] : null,
        (int) $payload['material_id'],
        (string) $payload['title'],
        (string) $payload['urgency'],
        (string) $payload['recommendation'],
        (string) $payload['reason'],
        (string) $payload['suggested_action'],
        (string) ($payload['source'] ?? 'rules'),
        $payload['model_name'] ?? null,
        $payload['prompt_hash'] ?? null,
        $payload['raw_response_json'] ?? null,
        isset($payload['created_by']) ? (int) $payload['created_by'] : null,
    ]);

    return (int) $pdo->lastInsertId();
}

function create_recommendation_for_forecast_item(PDO $pdo, array $item, int $createdBy): int
{
    $context = recommendation_context_from_forecast_item($item);
    $enabled = setting_value($pdo, 'gemini_enabled', '1') === '1';
    $model = setting_value($pdo, 'gemini_model', (string) (ai_config()['gemini_model'] ?? 'gemini-2.0-flash'));
    $recommendation = gemini_generate_recommendation($context, $model, $enabled);
    $recommendation['forecast_run_id'] = (int) $item['forecast_run_id'];
    $recommendation['forecast_item_id'] = (int) $item['id'];
    $recommendation['material_id'] = (int) $item['material_id'];
    $recommendation['created_by'] = $createdBy;

    return save_ai_recommendation($pdo, $recommendation);
}
