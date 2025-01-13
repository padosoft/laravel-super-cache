<?php

namespace Padosoft\SuperCache\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Padosoft\SuperCache\RedisConnector;
use Padosoft\SuperCache\Service\GetClusterNodesService;

class GetClusterNodesCommand extends Command
{
    protected $signature = 'supercache:get-cluster-nodes
                                {--connection_name= : (opzionale) nome della connessione redis }
                                ';
    protected $description = 'Comando per ottenere i nodi del cluster sulla connessione specificata';
    protected RedisConnector $redis;
    protected GetClusterNodesService $service;

    public function __construct(RedisConnector $redis, GetClusterNodesService $service)
    {
        parent::__construct();
        $this->redis = $redis;
        $this->service = $service;
    }

    /**
     * @throws \JsonException
     */
    public function handle(): void
    {
        try {
            $array_nodi = $this->service->getClusterNodes($this->option('connection_name'));
            $this->output->writeln(json_encode($array_nodi, JSON_THROW_ON_ERROR));
        } catch (\Throwable $e) {
            Log::error('Errore durante il recupero dei nodi del cluster ' . $e->getMessage());
            $this->output->writeln(json_encode([], JSON_THROW_ON_ERROR));
        }
    }
}
