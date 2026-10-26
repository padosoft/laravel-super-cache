<?php

namespace Padosoft\SuperCache\Service;

use Illuminate\Support\Facades\Log;
use Padosoft\SuperCache\SuperCacheManager;

/**
 * Shutdown function registrata da SuperCacheServiceProvider.
 *
 * Quando PHP muore con "Allowed memory size exhausted" mentre flushByTags() sta lavorando, Laravel logga
 * solo file e riga del fatal error: qui si aggiunge il tag che si stava invalidando, cioè l'informazione
 * che serve per capire quale tag ha troppe chiavi.
 */
class MemoryExhaustionLogger
{
    public const MESSAGE = 'SuperCache: memory exhausted while flushing tag';

    public function __invoke(): void
    {
        self::handle(error_get_last(), SuperCacheManager::getCurrentFlushTag());
    }

    /**
     * @param array|null  $error ultimo errore PHP, come restituito da error_get_last()
     * @param string|null $tag   tag in lavorazione al momento dell'errore
     */
    public static function handle(?array $error, ?string $tag): void
    {
        if ($error === null || $tag === null) {
            // Nessun errore, oppure out-of-memory avvenuto fuori da flushByTags: non è attribuibile a SuperCache
            return;
        }

        $message = (string) ($error['message'] ?? '');
        if (!str_contains($message, 'Allowed memory size') && !str_contains($message, 'Out of memory')) {
            return;
        }

        // Dopo un out-of-memory non resta memoria per il logger: senza alzare il limite questo stesso
        // handler muore con un secondo fatal error e il log non viene scritto.
        ini_set('memory_limit', '-1');

        try {
            Log::error(self::MESSAGE, [
                'tag' => $tag,
                'error' => $message,
                'file' => $error['file'] ?? null,
                'line' => $error['line'] ?? null,
            ]);
        } catch (\Throwable $e) {
            // Siamo in shutdown dopo un fatal error: non c'è nulla di meglio da fare
        }
    }
}
