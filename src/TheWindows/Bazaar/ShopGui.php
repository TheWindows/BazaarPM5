<?php
declare(strict_types=1);

namespace TheWindows\Bazaar;

use muqsit\invmenu\InvMenu;
use muqsit\invmenu\transaction\InvMenuTransaction;
use muqsit\invmenu\transaction\InvMenuTransactionResult;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\utils\DyeColor;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\player\Player;
use onebone\economyapi\EconomyAPI;

class ShopGui {
    private InvMenu $menu;
    private \pocketmine\plugin\Plugin $plugin;
    private ?EconomyAPI $economy;
    private ?DatabaseManager $dbManager;
    private string $currentCategory;
    private int $currentPage;
    private array $shopItems;

    private const CATEGORIES = [
        "blocks" => [
            "name" => "Blocks",
            "icon" => "stone",
            "slot" => 9
        ],
        "tools" => [
            "name" => "Tools & Armor",
            "icon" => "diamond_pickaxe",
            "slot" => 18
        ],
        "food" => [
            "name" => "Food & Farming",
            "icon" => "apple",
            "slot" => 27
        ],
        "misc" => [
            "name" => "Miscellaneous",
            "icon" => "ender_pearl",
            "slot" => 36
        ]
    ];

    public function __construct(\pocketmine\plugin\Plugin $plugin, ?EconomyAPI $economy, ?DatabaseManager $dbManager, string $category = "blocks", int $page = 0) {
        $this->plugin = $plugin;
        $this->economy = $economy;
        $this->dbManager = $dbManager;
        $this->currentCategory = in_array($category, array_keys(self::CATEGORIES)) ? $category : "blocks";
        $this->currentPage = max(0, $page);
        
        $this->menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
        $this->menu->setName((string) $this->plugin->getConfig()->getNested("messages.gui_title", "§l§e» §r§bBazaar §l§e«"));
        
        $this->initShopItems();
        
        $this->menu->setListener(function (InvMenuTransaction $transaction): InvMenuTransactionResult {
            $player = $transaction->getPlayer();
            $slot = $transaction->getAction()->getSlot();
            
            try {
                foreach (self::CATEGORIES as $id => $category) {
                    if ($slot === $category["slot"]) {
                        $this->currentCategory = $id;
                        $this->currentPage = 0;
                        $this->initInventory($player);
                        return $transaction->discard();
                    }
                }
                
                if ($slot === 48 && $this->currentPage > 0) {
                    $this->currentPage--;
                    $this->initInventory($player);
                    return $transaction->discard();
                }
                
                if ($slot === 50 && $this->hasNextPage()) {
                    $this->currentPage++;
                    $this->initInventory($player);
                    return $transaction->discard();
                }
                
                $itemSlots = [11,12,13,14,15,16,20,21,22,23,24,25,29,30,31,32,33,34,38,39,40,41,42,43];
                if (in_array($slot, $itemSlots, true)) {
                    $shopItem = $this->getItemInSlot($slot);
                    if ($shopItem !== null && $this->economy instanceof EconomyAPI) {
                        $itemTransactionGui = new ItemTransactionGui(
                            $this->plugin, 
                            $this->economy,
                            $this->dbManager,
                            $shopItem["item"], 
                            $shopItem["buyPrice"], 
                            $shopItem["sellPrice"],
                            $shopItem["id"]
                        );
                        $itemTransactionGui->open($player);
                    }
                }
            } catch (\Exception $e) {
                $player->sendMessage(str_replace("{error}", $e->getMessage(), $this->plugin->getConfig()->getNested("messages.transaction_error", "§cError processing transaction: {error}")));
            }
            
            return $transaction->discard();
        });
    }

    private function initShopItems(): void {
        $fallbackItem = VanillaBlocks::STONE()->asItem()->setCustomName("§rStone");

        $configItems = $this->plugin->getConfig()->get("items", []);
        $customItems = $this->plugin->getConfig()->get("custom-items", []);
        if (!is_array($configItems)) {
            $configItems = [];
        }
        if (!is_array($customItems)) {
            $customItems = [];
        }
        $dbItems = $this->dbManager ? $this->dbManager->getAllItemPrices() : [];

        $this->shopItems = [
            "blocks" => [],
            "tools" => [],
            "food" => [],
            "misc" => []
        ];

        $addEntry = function (string $category, string $itemId, array $data) use ($dbItems, $fallbackItem): void {
            $target = isset($this->shopItems[$category]) ? $category : "misc";
            if ($target !== $category) {
                $this->plugin->getLogger()->warning("Unknown category '$category' for item '$itemId', placing it in 'misc'. Use blocks/tools/food/misc.");
            }

            $priceData = DatabaseManager::extractPrices($itemId, $data);
            if ($priceData === null) {
                $this->plugin->getLogger()->warning("Invalid price data for item '$itemId' in category '$category', skipping. Required: buy + sell numbers.");
                return;
            }

            $item = CustomItemParser::parse($itemId, $data, $this->plugin);
            if ($item === null) {
                $this->plugin->getLogger()->warning("Invalid item '$itemId' in category '$category', using fallback item (stone)");
                $item = clone $fallbackItem;
                $item->setCustomName("§rReplacement: $itemId");
            }

            $dbData = $dbItems[$itemId] ?? null;
            $this->shopItems[$target][$itemId] = [
                "item" => $item,
                "buy" => $dbData["buy_price"] ?? $priceData["buy"],
                "sell" => $dbData["sell_price"] ?? $priceData["sell"]
            ];
        };

        foreach ($configItems as $category => $items) {
            if (!is_array($items)) {
                continue;
            }
            foreach ($items as $itemId => $data) {
                if (!is_array($data)) {
                    $this->plugin->getLogger()->warning("Invalid entry for item '$itemId' in category '$category', skipping.");
                    continue;
                }
                $addEntry((string) $category, (string) $itemId, $data);
            }
        }

        if (is_array($customItems)) {
            foreach ($customItems as $itemId => $data) {                if (!is_array($data)) {
                    $this->plugin->getLogger()->warning("Invalid custom-item entry '$itemId', skipping.");
                    continue;
                }
                $category = isset($data["category"]) && is_string($data["category"]) && $data["category"] !== ""
                    ? $data["category"]
                    : "misc";
                $addEntry($category, (string) $itemId, $data);
            }
        }
    }

    /**
     * Re-read live prices from the database (used after random
     * fluctuations, resets or reloads) without rebuilding items.
     */
    public function refreshPrices(): void {
        if ($this->dbManager === null) {
            return;
        }
        $dbItems = $this->dbManager->getAllItemPrices();
        foreach ($this->shopItems as $category => $items) {
            foreach ($items as $itemId => $entry) {
                if (isset($dbItems[$itemId])) {
                    $this->shopItems[$category][$itemId]["buy"] = $dbItems[$itemId]["buy_price"];
                    $this->shopItems[$category][$itemId]["sell"] = $dbItems[$itemId]["sell_price"];
                }
            }
        }
    }

    /**
     * Rebuild the whole catalogue from config + DB (used after /bazaar reload).
     */
    public function reloadItems(): void {
        $this->initShopItems();
    }

    public function open(Player $player): void {
        if (!$this->economy instanceof EconomyAPI) {
            $player->sendMessage($this->plugin->getConfig()->getNested("messages.economy_unavailable", "§cEconomy system is not available!"));
            return;
        }
        $this->initInventory($player);
        $this->menu->send($player);
    }

    private function initInventory(Player $player): void {
        $inventory = $this->menu->getInventory();
        $inventory->clearAll();
        
        $background = VanillaBlocks::STAINED_GLASS_PANE()->setColor(DyeColor::GRAY())->asItem()->setCustomName(" ");
        for ($i = 0; $i < 54; $i++) {
            $inventory->setItem($i, $background);
        }
        
        $border = VanillaBlocks::STAINED_GLASS_PANE()->setColor(DyeColor::GRAY())->asItem()->setCustomName("§r§l§eBazaar Border");
        $borderSlots = [0,1,2,3,4,5,6,7,8,9,17,18,26,27,35,36,44,45,46,47,48,49,50,51,52,53];
        foreach ($borderSlots as $slot) {
            $inventory->setItem($slot, $border);
        }
        
        foreach (self::CATEGORIES as $id => $category) {
            $item = StringToItemParser::getInstance()->parse($category["icon"]) ?? StringToItemParser::getInstance()->parse("paper");
            if ($item === null) {
                continue;
            }
            
            if ($id === $this->currentCategory) {
                try {
                    $item->addEnchantment(new EnchantmentInstance(VanillaEnchantments::PROTECTION(), 1));
                } catch (\Exception $e) {
                    $this->plugin->getLogger()->warning("Failed to add enchantment to category icon: " . $e->getMessage());
                }
                $item->setCustomName("§e§l" . $category["name"] . " §r§7(Selected)");
            } else {
                $item->setCustomName("§e§l" . $category["name"]);
            }
            
            $inventory->setItem($category["slot"], $item);
        }
        
        if ($this->currentPage > 0) {
            $prevPage = StringToItemParser::getInstance()->parse("arrow")->setCustomName("§aPrevious Page");
            $inventory->setItem(48, $prevPage);
        }
        
        if ($this->hasNextPage()) {
            $nextPage = StringToItemParser::getInstance()->parse("arrow")->setCustomName("§aNext Page");
            $inventory->setItem(50, $nextPage);
        }
        
        $totalPages = max(1, (int)ceil(count($this->shopItems[$this->currentCategory] ?? []) / 24));
        $pageInfo = StringToItemParser::getInstance()->parse("paper")->setCustomName("§ePage " . ($this->currentPage + 1) . "/" . $totalPages);
        $inventory->setItem(49, $pageInfo);
        
        $items = $this->shopItems[$this->currentCategory] ?? [];
        $startIndex = $this->currentPage * 24;
        $itemSlots = [11,12,13,14,15,16,20,21,22,23,24,25,29,30,31,32,33,34,38,39,40,41,42,43];
        
        for ($i = 0; $i < 24; $i++) {
            $index = $startIndex + $i;
            $slot = $itemSlots[$i] ?? null;
            
            if ($slot === null) {
                continue;
            }
            
            $itemKeys = array_keys($items);
            if (isset($itemKeys[$index])) {
                $itemId = $itemKeys[$index];
                $itemData = $items[$itemId];
                
                $displayItem = clone $itemData["item"];
                $displayItem->setCount(1);
                $existingLore = $displayItem->getLore();
                $displayItem->setLore(array_merge(
                    $existingLore,
                    $existingLore === [] ? [] : ["§r"],
                    [
                        "§r",
                        "§7Buy Price: §a§l$" . number_format($itemData["buy"], 2) . "§r",
                        "§7Sell Price: §6§l$" . number_format($itemData["sell"], 2) . "§r",
                        "§r",
                        "§eClick to view options"
                    ]
                ));
                
                $inventory->setItem($slot, $displayItem);
            } else {
                $inventory->clear($slot);
            }
        }
    }

    private function hasNextPage(): bool {
        $items = $this->shopItems[$this->currentCategory] ?? [];
        return count($items) > ($this->currentPage + 1) * 24;
    }

    private function getItemInSlot(int $slot): ?array {
        $items = $this->shopItems[$this->currentCategory] ?? [];
        $startIndex = $this->currentPage * 24;
        $itemSlots = [11,12,13,14,15,16,20,21,22,23,24,25,29,30,31,32,33,34,38,39,40,41,42,43];
        
        $index = array_search($slot, $itemSlots, true);
        if ($index === false) {
            return null;
        }
        
        $itemIndex = $startIndex + $index;
        $itemIds = array_keys($items);
        
        if (isset($itemIds[$itemIndex])) {
            $itemId = $itemIds[$itemIndex];
            return [
                "id" => $itemId,
                "item" => $items[$itemId]["item"],
                "buyPrice" => $items[$itemId]["buy"],
                "sellPrice" => $items[$itemId]["sell"]
            ];
        }
        
        return null;
    }
}