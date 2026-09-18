<?php
declare(strict_types=1);

namespace TheWindows\Bazaar;

use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use SQLite3;

class DatabaseManager {
    private SQLite3 $db;
    private PluginBase $plugin;

    public function __construct(string $dbPath, PluginBase $plugin) {
        $this->plugin = $plugin;
        $this->db = new SQLite3($dbPath);

        if (!$this->db) {
            throw new \Exception("Failed to open SQLite database at: " . $dbPath);
        }

        $this->db->exec("PRAGMA foreign_keys = ON;");
        $this->createTables();
        $this->syncPricesWithConfig();
    }

    private function createTables(): void {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS item_prices (
                item_id TEXT PRIMARY KEY,
                category TEXT,
                buy_price REAL,
                sell_price REAL,
                min_price REAL,
                max_price REAL
            )
        ");

        $this->db->exec("
            CREATE TABLE IF NOT EXISTS transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                item_id TEXT,
                player_name TEXT,
                type TEXT CHECK(type IN ('buy', 'sell')),
                amount INTEGER,
                total_price REAL,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
    }

    /**
     * Collect every sellable entry from config.
     * Supports legacy `items.<category>.<id> = {buy, sell, ...}`
     * and extended custom definitions (extra keys like item/custom-name/lore/enchantments),
     * plus a top-level `custom-items` section merged by its `category` key (default: misc).
     *
     * @return array<string, array{category: string, buy: float, sell: float, min_price: float|null, max_price: float|null}>
     */
    public static function collectConfigEntries(array $configItems, array $customItems): array {
        $entries = [];

        foreach ($configItems as $category => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $itemId => $data) {
                if (!is_array($data)) {
                    continue;
                }
                $parsed = self::extractPrices((string) $itemId, $data);
                if ($parsed === null) {
                    continue;
                }
                $entries[(string) $itemId] = [
                    "category" => (string) $category,
                    "buy" => $parsed["buy"],
                    "sell" => $parsed["sell"],
                    "min_price" => $parsed["min_price"],
                    "max_price" => $parsed["max_price"]
                ];
            }
        }

        foreach ($customItems as $itemId => $data) {
            if (!is_array($data)) {
                continue;
            }
            $parsed = self::extractPrices((string) $itemId, $data);
            if ($parsed === null) {
                continue;
            }
            $category = isset($data["category"]) && is_string($data["category"]) && $data["category"] !== ""
                ? $data["category"]
                : "misc";
            $entries[(string) $itemId] = [
                "category" => $category,
                "buy" => $parsed["buy"],
                "sell" => $parsed["sell"],
                "min_price" => $parsed["min_price"],
                "max_price" => $parsed["max_price"]
            ];
        }

        return $entries;
    }

    /**
     * Extract buy/sell/min/max from a (possibly extended custom-item) definition.
     * Returns null when buy/sell are missing or invalid.
     *
     * @return array{buy: float, sell: float, min_price: float|null, max_price: float|null}|null
     */
    public static function extractPrices(string $itemId, array $data): ?array {
        if (!isset($data["buy"], $data["sell"]) || !is_numeric($data["buy"]) || !is_numeric($data["sell"])) {
            return null;
        }
        $buy = (float) $data["buy"];
        $sell = (float) $data["sell"];
        if ($buy < 0 || $sell < 0) {
            return null;
        }
        $min = isset($data["min_price"]) && is_numeric($data["min_price"]) ? (float) $data["min_price"] : null;
        $max = isset($data["max_price"]) && is_numeric($data["max_price"]) ? (float) $data["max_price"] : null;
        if ($min === null) {
            $min = $buy * 0.8;
        }
        if ($max === null) {
            $max = $buy * 1.2;
        }
        if ($min > $max) {
            [$min, $max] = [$max, $min];
        }
        return ["buy" => $buy, "sell" => $sell, "min_price" => $min, "max_price" => $max];
    }

    /**
     * Sync config -> DB without wiping out active (randomly fluctuated) prices.
     * New items are inserted, min/max/category are refreshed, unknown DB rows
     * that no longer exist in config are pruned.
     */
    public function syncPricesWithConfig(): void {
        $config = $this->plugin->getConfig();
        $rawItems = $config->get("items", []);
        $rawCustom = $config->get("custom-items", []);
        $entries = self::collectConfigEntries(
            is_array($rawItems) ? $rawItems : [],
            is_array($rawCustom) ? $rawCustom : []
        );

        $insert = $this->db->prepare("INSERT OR IGNORE INTO item_prices (item_id, category, buy_price, sell_price, min_price, max_price) VALUES (:item_id, :category, :buy_price, :sell_price, :min_price, :max_price)");
        $updateMeta = $this->db->prepare("UPDATE item_prices SET category = :category, min_price = :min_price, max_price = :max_price WHERE item_id = :item_id");

        foreach ($entries as $itemId => $entry) {
            $insert->bindValue(":item_id", $itemId, SQLITE3_TEXT);
            $insert->bindValue(":category", $entry["category"], SQLITE3_TEXT);
            $insert->bindValue(":buy_price", $entry["buy"], SQLITE3_FLOAT);
            $insert->bindValue(":sell_price", $entry["sell"], SQLITE3_FLOAT);
            $insert->bindValue(":min_price", $entry["min_price"], SQLITE3_FLOAT);
            $insert->bindValue(":max_price", $entry["max_price"], SQLITE3_FLOAT);
            $insert->execute();
            $insert->reset();

            // Keep live (fluctuated) buy/sell prices, but always refresh
            // category and bounds so config edits still apply. Clamp live
            // prices into the new bounds so they never escape min/max.
            $updateMeta->bindValue(":category", $entry["category"], SQLITE3_TEXT);
            $updateMeta->bindValue(":min_price", $entry["min_price"], SQLITE3_FLOAT);
            $updateMeta->bindValue(":max_price", $entry["max_price"], SQLITE3_FLOAT);
            $updateMeta->bindValue(":item_id", $itemId, SQLITE3_TEXT);
            $updateMeta->execute();
            $updateMeta->reset();
        }
        $insert->close();
        $updateMeta->close();

        $this->clampAllToBounds();
        $this->pruneRemovedItems(array_keys($entries));
    }

    private function clampAllToBounds(): void {
        $this->db->exec("UPDATE item_prices SET buy_price = CASE WHEN buy_price < min_price THEN min_price WHEN buy_price > max_price THEN max_price ELSE buy_price END WHERE min_price IS NOT NULL AND max_price IS NOT NULL");
    }

    private function pruneRemovedItems(array $validIds): void {
        if (count($validIds) === 0) {
            return;
        }
        // SQLite3Stmt uses named params more reliably; build manually with escaping instead.
        $escaped = array_map(fn(string $id): string => "'" . $this->db->escapeString($id) . "'", $validIds);
        $this->db->exec("DELETE FROM item_prices WHERE item_id NOT IN (" . implode(",", $escaped) . ")");
    }

    /**
     * Force every DB price back to the values written in config.yml.
     */
    public function resetPricesFromConfig(): void {
        $config = $this->plugin->getConfig();
        $rawItems = $config->get("items", []);
        $rawCustom = $config->get("custom-items", []);
        $entries = self::collectConfigEntries(
            is_array($rawItems) ? $rawItems : [],
            is_array($rawCustom) ? $rawCustom : []
        );

        $stmt = $this->db->prepare("INSERT OR REPLACE INTO item_prices (item_id, category, buy_price, sell_price, min_price, max_price) VALUES (:item_id, :category, :buy_price, :sell_price, :min_price, :max_price)");
        foreach ($entries as $itemId => $entry) {
            $stmt->bindValue(":item_id", $itemId, SQLITE3_TEXT);
            $stmt->bindValue(":category", $entry["category"], SQLITE3_TEXT);
            $stmt->bindValue(":buy_price", $entry["buy"], SQLITE3_FLOAT);
            $stmt->bindValue(":sell_price", $entry["sell"], SQLITE3_FLOAT);
            $stmt->bindValue(":min_price", $entry["min_price"], SQLITE3_FLOAT);
            $stmt->bindValue(":max_price", $entry["max_price"], SQLITE3_FLOAT);
            $stmt->execute();
            $stmt->reset();
        }
        $stmt->close();
        $this->pruneRemovedItems(array_keys($entries));
    }

    public function getAllItemPrices(): array {
        $result = $this->db->query("SELECT * FROM item_prices");
        $items = [];

        if ($result === false) {
            return $items;
        }
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $items[$row["item_id"]] = [
                "category" => $row["category"],
                "buy_price" => (float) $row["buy_price"],
                "sell_price" => (float) $row["sell_price"],
                "min_price" => $row["min_price"] !== null ? (float) $row["min_price"] : null,
                "max_price" => $row["max_price"] !== null ? (float) $row["max_price"] : null
            ];
        }

        return $items;
    }

    public function getPrice(string $itemId): ?array {
        $stmt = $this->db->prepare("SELECT * FROM item_prices WHERE item_id = :item_id LIMIT 1");
        $stmt->bindValue(":item_id", $itemId, SQLITE3_TEXT);
        $result = $stmt->execute();
        $row = $result !== false ? $result->fetchArray(SQLITE3_ASSOC) : false;
        $stmt->close();
        if ($row === false) {
            return null;
        }
        return [
            "category" => $row["category"],
            "buy_price" => (float) $row["buy_price"],
            "sell_price" => (float) $row["sell_price"],
            "min_price" => $row["min_price"] !== null ? (float) $row["min_price"] : null,
            "max_price" => $row["max_price"] !== null ? (float) $row["max_price"] : null
        ];
    }

    public function setPrice(string $itemId, float $buyPrice, float $sellPrice): void {
        $stmt = $this->db->prepare("UPDATE item_prices SET buy_price = :buy_price, sell_price = :sell_price WHERE item_id = :item_id");
        $stmt->bindValue(":buy_price", $buyPrice, SQLITE3_FLOAT);
        $stmt->bindValue(":sell_price", $sellPrice, SQLITE3_FLOAT);
        $stmt->bindValue(":item_id", $itemId, SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();
    }

    public function recordTransaction(string $itemId, string $playerName, string $type, int $amount, float $totalPrice): void {
        $stmt = $this->db->prepare("INSERT INTO transactions (item_id, player_name, type, amount, total_price) VALUES (:item_id, :player_name, :type, :amount, :total_price)");
        $stmt->bindValue(":item_id", $itemId, SQLITE3_TEXT);
        $stmt->bindValue(":player_name", $playerName, SQLITE3_TEXT);
        $stmt->bindValue(":type", $type, SQLITE3_TEXT);
        $stmt->bindValue(":amount", $amount, SQLITE3_INTEGER);
        $stmt->bindValue(":total_price", $totalPrice, SQLITE3_FLOAT);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Randomly fluctuate every stored price inside its [min_price, max_price] bounds.
     * The buy/sell ratio of each item is preserved (sell is scaled with buy).
     *
     * @param Config $config plugin config (reads price-auto-update.*)
     * @param bool $force run even when price-auto-update.enabled is false (admin command)
     * @return array<string, array{old_buy: float, new_buy: float, old_sell: float, new_sell: float}> changed rows
     */
    public function updatePrices(Config $config, bool $force = false): array {
        if (!$force && !$config->getNested("price-auto-update.enabled", false)) {
            return [];
        }

        $maxFluctuation = (float) $config->getNested("price-auto-update.max-fluctuation", 0.1);
        if ($maxFluctuation < 0) {
            $maxFluctuation = 0.0;
        }
        if ($maxFluctuation > 1.0) {
            $maxFluctuation = 1.0;
        }

        $changes = [];
        $stmt = $this->db->prepare("UPDATE item_prices SET buy_price = :buy_price, sell_price = :sell_price WHERE item_id = :item_id");

        $items = $this->getAllItemPrices();
        foreach ($items as $itemId => $data) {
            $buy = (float) $data["buy_price"];
            $sell = (float) $data["sell_price"];
            if ($buy <= 0) {
                continue;
            }
            // Uniform fluctuation in [-maxFluctuation, +maxFluctuation].
            $fluctuation = (mt_rand(-10000, 10000) / 10000) * $maxFluctuation;
            $newBuy = $buy * (1 + $fluctuation);
            if ($data["min_price"] !== null) {
                $newBuy = max($data["min_price"], $newBuy);
            }
            if ($data["max_price"] !== null) {
                $newBuy = min($data["max_price"], $newBuy);
            }
            $newBuy = round($newBuy, 2);

            // Preserve the configured buy/sell ratio instead of hardcoding one.
            $ratio = $buy > 0 ? $sell / $buy : 0.0;
            $newSell = round($newBuy * $ratio, 2);

            if ($newBuy === round($buy, 2) && $newSell === round($sell, 2)) {
                continue;
            }

            $stmt->bindValue(":buy_price", $newBuy, SQLITE3_FLOAT);
            $stmt->bindValue(":sell_price", $newSell, SQLITE3_FLOAT);
            $stmt->bindValue(":item_id", $itemId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->reset();

            $changes[$itemId] = [
                "old_buy" => round($buy, 2),
                "new_buy" => $newBuy,
                "old_sell" => round($sell, 2),
                "new_sell" => $newSell
            ];
        }

        $stmt->close();
        return $changes;
    }

    public function close(): void {
        try {
            $this->db->close();
        } catch (\Throwable) {
            // Already closed.
        }
    }

    public function __destruct() {
        $this->close();
    }
}
