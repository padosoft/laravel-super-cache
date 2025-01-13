<?php

namespace Padosoft\SuperCache\Service;

use Illuminate\Support\Facades\Log;
use Padosoft\SuperCache\RedisConnector;

class GetClusterNodesService
{
    protected RedisConnector $redis;

    public function __construct(RedisConnector $redis)
    {
        $this->redis = $redis;
    }

    /**
     * Recupera l'elenco dei nodi in un cluster Redis.
     *
     * Questo metodo si connette al cluster Redis ed estrae l'elenco dei nodi, concentrandosi principalmente
     * sui nodi master. Include anche una logica opzionale per raccogliere tutti i nodi, inclusi gli slave, se necessario.
     * L'elenco risultante contiene informazioni uniche host:port per ciascun nodo.
     *
     * @param  string|null $connection Stringa opzionale per identificare la connessione. Se null, verrà utilizzata la connessione predefinita.
     * @return array       Un array di stringhe che rappresenta i host:port di ciascun nodo all'interno del cluster.
     */
    public function getClusterNodes(?string $connection = null): array
    {
        try {
            $array_nodi = []; // Alla fine in questo array dovrei avere le informazioni host:port di tutti i nodi che compongono il cluster
            $redisConnection = $this->redis->getRedisConnection($connection);
            // 1) Recupero i nodi master dalla connessione, in una configurazione standard in genere ci sono 3 master e "n" slave
            $masters = $redisConnection->_masters();
            // 2) Per ogni nodo master mi faccio dare i nodi a lui collegati
            foreach ($masters as $master) {
                $array_nodi[] = $master[0] . ':' . $master[1];

                // Dovrebbe essere sufficente avere i nodi master in quanto i nodi slave sono solo delle repliche e non inviano eventi,
                // inoltre le loro chiavi sono repliche di chiavi presenti in shard nei master
                // Se poi ci fossero dei problemi o si vede che perdiamo rroba, il copdoce sotto serve per avere tutti i nodi del cluster.
                // Attenzione che il comando 'CLUSTER NODES' su AWS non funziona, ma va abilitato

                /*
                $nodeInfo = $redisConnection->rawCommand($master[0] . ':' . $master[1], 'CLUSTER', 'NODES');
                // Questo comando restituisce una string che rappresenta in formato CSV le info sui nodi
                // Ogni riga è un nodo, ogni riga ha poi "n" colonne, a noi interessa la seconda colonna con host:port@port
                $splittedRow = explode(PHP_EOL, $nodeInfo);

                foreach ($splittedRow as $row) {
                    $splittedColumn = explode(' ', $row);
                    if (array_key_exists(1, $splittedColumn)) {
                        $infoNodo = $splittedColumn[1];
                        $splittedInfoNodo = explode('@', $infoNodo);
                        $hostAndPort = $splittedInfoNodo[0];
                        $array_nodi[] = $hostAndPort;
                    }
                }
                */
            }

            // 3) Tolgo i doppioni, infatti per la ridondanza ogni master ha in comune con glia ltri alcuni nodi
            //$array_nodi = array_unique($array_nodi);
            // Stampa l'array come JSON per il parsing lato script bash
            return $array_nodi;
        } catch (\Throwable $e) {
            Log::error('Errore durante il recupero dei nodi del cluster ' . $e->getMessage());

            return [];
        }
    }
}
