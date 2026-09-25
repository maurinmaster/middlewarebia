<?php

namespace BiaMiddleware;

use PDO;
use PDOException;

class Database {
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO {
        if (self::$pdo === null) {
            $dataDir = dirname(__DIR__) . '/data';
            if (!is_dir($dataDir)) {
                mkdir($dataDir, 0777, true);
            }
            $dbPath = $dataDir . '/database.sqlite';

            try {
                self::$pdo = new PDO('sqlite:' . $dbPath);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::initTables();
            } catch (PDOException $e) {
                Logger::error('Falha ao conectar no banco SQLite: ' . $e->getMessage());
                throw $e;
            }
        }

        return self::$pdo;
    }

    private static function initTables(): void {
        $db = self::$pdo;

        // Tabela de mapeamento de produtos entre eGestor e Nuvemshop
        $db->exec("CREATE TABLE IF NOT EXISTS product_mappings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            egestor_id TEXT,
            nuvemshop_product_id TEXT,
            nuvemshop_variant_id TEXT,
            sku TEXT,
            barcode TEXT,
            name TEXT,
            stock_egestor REAL DEFAULT 0,
            stock_nuvemshop REAL DEFAULT 0,
            price REAL DEFAULT 0,
            last_sync_at DATETIME,
            last_sync_direction TEXT,
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE INDEX IF NOT EXISTS idx_pm_egestor ON product_mappings(egestor_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pm_nuvem_p ON product_mappings(nuvemshop_product_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pm_nuvem_v ON product_mappings(nuvemshop_variant_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pm_sku ON product_mappings(sku)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pm_barcode ON product_mappings(barcode)");

        // Tabela de logs de sincronização
        $db->exec("CREATE TABLE IF NOT EXISTS sync_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            source TEXT,
            action TEXT,
            entity_id TEXT,
            details TEXT,
            status TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Tabela de configurações chave-valor
        $db->exec("CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
    }

    public static function logSync(string $source, string $action, string $entityId, string $details, string $status = 'success'): void {
        try {
            $db = self::getConnection();
            $stmt = $db->prepare("INSERT INTO sync_logs (source, action, entity_id, details, status) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$source, $action, $entityId, $details, $status]);
        } catch (\Exception $e) {
            Logger::error('Erro ao salvar log no banco: ' . $e->getMessage());
        }
    }

    public static function getSetting(string $key, ?string $default = null): ?string {
        $db = self::getConnection();
        $stmt = $db->prepare("SELECT value FROM settings WHERE key = ?");
        $stmt->execute([$key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    }

    public static function setSetting(string $key, string $value): void {
        $db = self::getConnection();
        $stmt = $db->prepare("INSERT INTO settings (key, value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([$key, $value]);
    }
}
