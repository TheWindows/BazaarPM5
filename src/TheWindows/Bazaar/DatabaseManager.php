<?php
declare(strict_types=1);

namespace TheWindows\Bazaar;

use pocketmine\plugin\PluginBase;
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

    private function syncPricesWithConfig(): void {
        $configItems = $this->plugin->getConfig()->get("items", []);
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO item_prices (item_id, category, buy_price, sell_price, min_price, max_price) VALUES (:item_id, :category, :buy_price, :sell_price, :min_price, :max_price)");
        
        foreach ($configItems as $category => $items) {
            foreach ($items as $itemId => $data) {
                $stmt->bindValue(':item_id', $itemId, SQLITE3_TEXT);
                $stmt->bindValue(':category', $category, SQLITE3_TEXT);
                $stmt->bindValue(':buy_price', $data["buy"], SQLITE3_FLOAT);
                $stmt->bindValue(':sell_price', $data["sell"], SQLITE3_FLOAT);
                $stmt->bindValue(':min_price', $data["min_price"], SQLITE3_FLOAT);
                $stmt->bindValue(':max_price', $data["max_price"], SQLITE3_FLOAT);
                $stmt->execute();
                $stmt->reset();
            }
        }
        $stmt->close();
    }

    public function getAllItemPrices(): array {
        $result = $this->db->query("SELECT * FROM item_prices");
        $items = [];
        
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $items[$row['item_id']] = [
                'buy_price' => $row['buy_price'],
                'sell_price' => $row['sell_price'],
                'min_price' => $row['min_price'],
                'max_price' => $row['max_price']
            ];
        }
        
        return $items;
    }

    public function recordTransaction(string $itemId, string $playerName, string $type, int $amount, float $totalPrice): void {
        $stmt = $this->db->prepare("INSERT INTO transactions (item_id, player_name, type, amount, total_price) VALUES (:item_id, :player_name, :type, :amount, :total_price)");
        $stmt->bindValue(':item_id', $itemId, SQLITE3_TEXT);
        $stmt->bindValue(':player_name', $playerName, SQLITE3_TEXT);
        $stmt->bindValue(':type', $type, SQLITE3_TEXT);
        $stmt->bindValue(':amount', $amount, SQLITE3_INTEGER);
        $stmt->bindValue(':total_price', $totalPrice, SQLITE3_FLOAT);
        $stmt->execute();
        $stmt->close();
    }

    public function updatePrices($config): void {
        if (!$config->getNested("price-auto-update.enabled", false)) {
            return;
        }

        $maxFluctuation = $config->getNested("price-auto-update.max-fluctuation", 0.1);
        $stmt = $this->db->prepare("UPDATE item_prices SET buy_price = :buy_price, sell_price = :sell_price WHERE item_id = :item_id");
        
        $items = $this->getAllItemPrices();
        foreach ($items as $itemId => $data) {
            $fluctuation = (mt_rand(-100, 100) / 1000) * $maxFluctuation;
            $newBuyPrice = max($data['min_price'], min($data['max_price'], $data['buy_price'] * (1 + $fluctuation)));
            $newSellPrice = $newBuyPrice * 0.8333; 
            
            $stmt->bindValue(':buy_price', $newBuyPrice, SQLITE3_FLOAT);
            $stmt->bindValue(':sell_price', $newSellPrice, SQLITE3_FLOAT);
            $stmt->bindValue(':item_id', $itemId, SQLITE3_TEXT);
            $stmt->execute();
            $stmt->reset();
        }
        
        $stmt->close();
    }

    public function __destruct() {
        $this->db->close();
    }
}