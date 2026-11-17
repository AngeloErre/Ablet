<?php
// Finto endpoint «chat completions» per i test. Il comportamento dipende dal nome del modello richiesto.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer sk-test-SEGRETO-1234' && ($_SERVER['HTTP_X_GOOG_API_KEY'] ?? '') !== 'sk-test-SEGRETO-1234') { http_response_code(401); echo '{"error":"unauthorized"}'; exit; }
if (str_ends_with($path, '/models')) { echo json_encode(['data' => [['id' => 'valid'], ['id' => 'invalid'], ['id' => 'leak']]]); exit; }
$in = json_decode(file_get_contents('php://input'), true);
$testModel = $in['model'] ?? '';
if (in_array($testModel, ['quota-test','busy-test','retry-test','timeout-test','date-test','gemini-quota-test'], true)) {
    file_put_contents(sys_get_temp_dir() . '/ablet_mock_requests.log', $testModel . "\n", FILE_APPEND);
    if ($testModel === 'timeout-test') { usleep(2300000); }
    else {
        http_response_code($testModel === 'busy-test' || $testModel === 'retry-test' ? 503 : 429);
        if ($testModel === 'date-test') { header('Retry-After: ' . gmdate('D, d M Y H:i:s', time()+25) . ' GMT'); }
        elseif (!in_array($testModel, ['retry-test','gemini-quota-test'], true)) { header('Retry-After: 12'); }
        echo json_encode(['error' => ['message' => 'Synthetic quota failure sk-test-SEGRETO-1234', 'details' => [['retryDelay' => '7.2s']]]]); exit;
    }
}
if (str_contains($path, ':generateContent')) {
    $audio = $in['contents'][0]['parts'][1]['inlineData'] ?? null;
    $parts = $audio ? [['text' => 'Devo studiare le frazioni.']] : [['inlineData' => ['mimeType' => 'audio/L16;rate=24000', 'data' => base64_encode(str_repeat("\0\0",240))]]];
    echo json_encode(['candidates' => [['content' => ['parts' => $parts]]]]); exit;
}
if (str_ends_with($path, '/interactions')) {
    echo json_encode(['steps' => [['type' => 'model_output', 'content' => [['type'=>'audio','data'=>base64_encode('RIFF-test-WAVE')]]]]]); exit;
}

file_put_contents(sys_get_temp_dir() . '/ablet_mock_last.json', json_encode($in));
$ok = ['student_message' => 'Qual è il prossimo piccolo passo che puoi fare da solo?', 'current_phase' => 'forethought', 'proposed_action' => 'continue',
    'proposed_state_update' => new stdClass(), 'evidence_message_ids' => [], 'suggested_support_level' => 3, 'safety_status' => 'ok'];
$content = match ($in['model']) {
    'invalid' => 'Certo! Ecco la soluzione completa del compito, senza JSON.',
    'leak' => json_encode(['student_message' => 'Ecco: Per muovere le macchine si usò la forza del vapore, ottenuta bruciando carbone. Chi lavorava non decideva più i propri tempi: seguiva gli orari della fabbrica.'] + $ok, JSON_UNESCAPED_UNICODE),
    'verbose' => json_encode(['student_message' => "**Ottimo lavoro!** Ora:\n- rileggi la consegna\n- pensa (con calma) a cosa sai già\n" . str_repeat('e poi continua a ragionare con attenzione sul compito ', 8) . 'Che cosa sai già? Cosa ti serve? Da dove parti?'] + $ok, JSON_UNESCAPED_UNICODE),
    'forge' => json_encode(['evidence_message_ids' => [1], 'proposed_state_update' => ['slots' => ['task_statement' => 'inventato']]] + $ok),
    default => "```json\n" . json_encode($ok, JSON_UNESCAPED_UNICODE) . "\n```",
};
echo json_encode(['choices' => [['message' => ['role' => 'assistant', 'content' => $content]]], 'usage' => ['prompt_tokens' => 900, 'completion_tokens' => 40]]);
