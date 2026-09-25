<?php

// Redireciona ou inclui a pasta pública caso o Apache não reescreva automaticamente
if (file_exists(__DIR__ . '/public/index.php')) {
    chdir(__DIR__ . '/public');
    require_once __DIR__ . '/public/index.php';
} else {
    http_response_code(500);
    echo "Erro: pasta 'public' não encontrada.";
}
