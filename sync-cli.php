<?php

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/src/Logger.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/EGestorClient.php';
require_once __DIR__ . '/src/NuvemshopClient.php';
require_once __DIR__ . '/src/SyncService.php';

use BiaMiddleware\Logger;
use BiaMiddleware\EGestorClient;
use BiaMiddleware\NuvemshopClient;
use BiaMiddleware\SyncService;

$config = require __DIR__ . '/config/config.php';

echo "=== Middleware Bia CLI Sync ===" . PHP_EOL;

$egestor = new EGestorClient($config['egestor']);
$nuvemshop = new NuvemshopClient($config['nuvemshop']);
$sync = new SyncService($egestor, $nuvemshop);

$action = $argv[1] ?? 'help';

switch ($action) {
    case 'import-egestor-to-nuvem':
        echo "Iniciando importação de todos os produtos do eGestor para a Nuvemshop..." . PHP_EOL;
        echo "Isso criará os produtos que faltam na Nuvemshop e sincronizará os estoques dos existentes." . PHP_EOL;
        $res = $sync->exportAllFromEGestorToNuvemshop(function($processed, $created, $updated, $errors, $name) {
            echo "-> [{$processed}] {$name} | Novos na Nuvemshop: {$created} | Atualizados: {$updated} | Erros: {$errors}\r";
        });
        echo PHP_EOL . "Resultado: " . ($res['message'] ?? 'Concluído') . PHP_EOL;
        break;

    case 'import-nuvem':
        echo "Importando catálogo da Nuvemshop..." . PHP_EOL;
        $res = $sync->importProductsFromNuvemshop();
        echo "Concluído! Total: " . ($res['total'] ?? 0) . " variantes importadas." . PHP_EOL;
        break;

    case 'sync-prod':
        $codigo = $argv[2] ?? null;
        if (!$codigo) {
            echo "Uso: php sync-cli.php sync-prod <CODIGO_EGESTOR>" . PHP_EOL;
            exit(1);
        }
        echo "Sincronizando produto eGestor ID {$codigo} com a Nuvemshop..." . PHP_EOL;
        $res = $sync->syncProductFromEGestor($codigo);
        print_r($res);
        break;

    case 'test-nuvem':
        echo "Testando conexão com a Nuvemshop..." . PHP_EOL;
        $res = $nuvemshop->testConnection();
        if ($res['success']) {
            echo "Sucesso! Loja: " . ($res['data']['name']['pt'] ?? 'N/A') . PHP_EOL;
        } else {
            echo "Erro: " . ($res['error'] ?? 'Falha') . PHP_EOL;
        }
        break;

    case 'test-egestor':
        echo "Testando conexão com eGestor..." . PHP_EOL;
        $res = $egestor->testConnection();
        if ($res['success']) {
            echo "Sucesso! Empresa: " . ($res['data']['nome'] ?? 'N/A') . PHP_EOL;
        } else {
            echo "Erro: " . ($res['error'] ?? 'Falha') . PHP_EOL;
        }
        break;

    default:
        echo "Comandos disponíveis:" . PHP_EOL;
        echo "  php sync-cli.php import-nuvem     - Importa todo o catálogo da Nuvemshop" . PHP_EOL;
        echo "  php sync-cli.php sync-prod <id>   - Sincroniza estoque de um produto do eGestor" . PHP_EOL;
        echo "  php sync-cli.php test-nuvem       - Testa credenciais da Nuvemshop" . PHP_EOL;
        echo "  php sync-cli.php test-egestor     - Testa credenciais do eGestor" . PHP_EOL;
        break;
}
