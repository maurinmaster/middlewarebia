<?php

require 'config/config.php';
require 'src/Logger.php';
require 'src/Database.php';
require 'src/EGestorClient.php';

$config = require 'config/config.php';
$client = new BiaMiddleware\EGestorClient($config['egestor']);
$res = $client->getProducts(1);

echo "Total no eGestor: " . ($res['data']['total'] ?? 0) . PHP_EOL;
echo "Paginas: " . ($res['data']['last_page'] ?? 0) . PHP_EOL;

if (!empty($res['data']['data'])) {
    foreach (array_slice($res['data']['data'], 0, 10) as $p) {
        echo "ID: " . $p['codigo'] . " | " . $p['descricao'] . " | SKU: '" . $p['codigoProprio'] . "' | EAN: '" . $p['refEanGtin'] . "' | Est: " . $p['estoque'] . PHP_EOL;
    }
}
