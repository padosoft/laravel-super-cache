<?php

return [
    // prefisso delle chiavi
    'prefix' => env('SUPERCACHE_PREFIX', 'supercache:'),
    // connessione Redis da usare
    'connection' => env('SUPERCACHE_CONNECTION', 'default'),
    // Numero di shard configurato
    'num_shards' => env('SUPERCACHE_NUM_SHARDS', 256),
    // Flag per abilitare/disabilitare il namespace suffix delle chiavi
    'use_namespace' => env('SUPERCACHE_USE_NAMESPACE', false),
    // Numero di namespace configurabili
    'num_namespace' => env('SUPERCACHE_NUM_NAMESPACE', 16),
    // Parametri per il batching del listner
    'batch_size' => env('SUPERCACHE_BATCH_SIZE', 100),
    'time_threshold' => env('SUPERCACHE_TIME_THRESHHOLD', 1),  // secondi
    'advancedMode' => env('SUPERCACHE_ADVANCED_MODE', 0),
    // Numero di chiavi lette per ogni SSCAN durante flushByTags (lotti più grandi = meno round-trip, più memoria)
    'flush_batch_size' => env('SUPERCACHE_FLUSH_BATCH_SIZE', 1000),
    // Se PHP muore per memoria esaurita durante flushByTags, logga il tag che si stava invalidando
    'log_memory_exhaustion' => env('SUPERCACHE_LOG_MEMORY_EXHAUSTION', true),
    'log_to_elastic_function' => env('SUPERCACHE_LOG_TO_ELASTIC_FUNCTION'),
];
