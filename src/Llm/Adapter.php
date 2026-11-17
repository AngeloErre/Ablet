<?php
declare(strict_types=1);

namespace Ablet\Llm;

interface Adapter
{
    /** Capacità dichiarate: nessuna equivalenza presunta fra provider.
     * @return array{text:bool,structured_output:string,streaming:bool,voice:bool,model_listing:bool} */
    public function capabilities(): array;

    /**
     * @param array<int,array{role:string,content:string}> $messages ruoli 'user' | 'assistant', già alternati; l'ultimo è 'user'
     * @param array<string,mixed> $params temperature, max_output_tokens (altri parametri ignorati)
     * @return array{text:string,tokens_in:?int,tokens_out:?int,http_status:?int}
     * @throws ProviderException
     */
    public function generate(string $system, array $messages, array $params): array;

    /** @return string[] identificativi dei modelli restituiti dal provider (mai codificati nell'applicazione) */
    public function listModels(): array;
}
