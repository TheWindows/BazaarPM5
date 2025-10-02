<?php

declare(strict_types=1);

namespace TheWindows\Bazaar;

use muqsit\invmenu\InvMenu;
use muqsit\invmenu\InvMenuHandler;
use muqsit\invmenu\transaction\InvMenuTransaction;
use muqsit\invmenu\transaction\InvMenuTransactionResult;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\utils\DyeColor;
use pocketmine\item\enchantment\EnchantmentInstance;
use pocketmine\item\enchantment\VanillaEnchantments;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use onebone\economyapi\EconomyAPI;
use jojoe77777\FormAPI\CustomForm;
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase {
    private ?ShopGui $shopGui = null;

    protected function onEnable(): void {
        if (!InvMenuHandler::isRegistered()) {
            InvMenuHandler::register($this);
        }
        
        $economy = $this->getServer()->getPluginManager()->getPlugin("EconomyAPI");
        if (!$economy instanceof EconomyAPI) {
            $this->getLogger()->error("EconomyAPI plugin not found! Disabling plugin...");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        $formAPI = $this->getServer()->getPluginManager()->getPlugin("FormAPI");
        if (!$formAPI) {
            $this->getLogger()->error("FormAPI not found! Custom amount form will not work.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        
        $this->shopGui = new ShopGui($this, $economy);
        $this->getServer()->getCommandMap()->register("winshop", new class($this) extends \pocketmine\command\Command {
            private Main $plugin;

            public function __construct(Main $plugin) {
                parent::__construct("bazaar", "Open the bazaar GUI", "/bazaar");
                $this->setPermission("winshop.command");
                $this->plugin = $plugin;
            }

            public function execute(\pocketmine\command\CommandSender $sender, string $commandLabel, array $args): bool {
                if (!$sender instanceof Player) {
                    $sender->sendMessage(TextFormat::RED . "This command can only be used in-game!");
                    return false;
                }
                
                if (!$this->testPermission($sender)) {
                    return false;
                }
                
                $this->plugin->getShopGui()->open($sender);
                return true;
            }
        });

        
        $this->getServer()->getPluginManager()->registerEvents(new class($economy) implements \pocketmine\event\Listener {
            private EconomyAPI $economy;

            public function __construct(EconomyAPI $economy) {
                $this->economy = $economy;
            }

            public function onPlayerJoin(\pocketmine\event\player\PlayerJoinEvent $event): void {
                $player = $event->getPlayer();
                if ($this->economy->myMoney($player) === null) {
                    $this->economy->setMoney($player, 100);
                }
            }
        }, $this);
    }

    public function getShopGui(): ?ShopGui {
        return $this->shopGui;
    }
}

class ShopGui {
    private InvMenu $menu;
    private \pocketmine\plugin\Plugin $plugin;
    private ?EconomyAPI $economy;
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
            "name" => "Tools & Weapons",
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

    public function __construct(\pocketmine\plugin\Plugin $plugin, ?EconomyAPI $economy, string $category = "blocks", int $page = 0) {
        $this->plugin = $plugin;
        $this->economy = $economy;
        $this->currentCategory = in_array($category, array_keys(self::CATEGORIES)) ? $category : "blocks";
        $this->currentPage = max(0, $page);
        
        $this->menu = InvMenu::create(InvMenu::TYPE_DOUBLE_CHEST);
        $this->menu->setName("§l§e» §r§bBazaar §l§e«");
        
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
                            $shopItem["item"], 
                            $shopItem["buyPrice"], 
                            $shopItem["sellPrice"],
                            $shopItem["id"]
                        );
                        $itemTransactionGui->open($player);
                    }
                }
            } catch (\Exception $e) {
                $player->sendMessage(TextFormat::RED . "Error processing transaction: " . $e->getMessage());
            }
            
            return $transaction->discard();
        });
    }

private function initShopItems(): void {
    $parser = StringToItemParser::getInstance();
    $fallbackItem = VanillaBlocks::BARRIER()->asItem()->setCustomName("§cInvalid Item");
    $this->shopItems = [
        "blocks" => [
            "stone" => ["item" => $parser->parse("stone") ?? $fallbackItem, "buy" => 5, "sell" => 0.42],
            "grass" => ["item" => VanillaBlocks::GRASS()->asItem(), "buy" => 10, "sell" => 0.83],
            "dirt" => ["item" => VanillaBlocks::DIRT()->asItem(), "buy" => 2.5, "sell" => 0.21],
            "cobblestone" => ["item" => VanillaBlocks::COBBLESTONE()->asItem(), "buy" => 5, "sell" => 0.42],
            "sand" => ["item" => VanillaBlocks::SAND()->asItem(), "buy" => 5, "sell" => 0.42],
            "gravel" => ["item" => VanillaBlocks::GRAVEL()->asItem(), "buy" => 5, "sell" => 0.42],
            "clay" => ["item" => VanillaBlocks::CLAY()->asItem(), "buy" => 20, "sell" => 1.67],
            "snow_block" => ["item" => VanillaBlocks::SNOW()->asItem(), "buy" => 15, "sell" => 1.25],
            "oak_planks" => ["item" => VanillaBlocks::OAK_PLANKS()->asItem(), "buy" => 5, "sell" => 0.42],
            "spruce_planks" => ["item" => VanillaBlocks::SPRUCE_PLANKS()->asItem(), "buy" => 5, "sell" => 0.42],
            "birch_planks" => ["item" => VanillaBlocks::BIRCH_PLANKS()->asItem(), "buy" => 5, "sell" => 0.42],
            "jungle_planks" => ["item" => VanillaBlocks::JUNGLE_PLANKS()->asItem(), "buy" => 5, "sell" => 0.42],
            "acacia_planks" => ["item" => VanillaBlocks::ACACIA_PLANKS()->asItem(), "buy" => 5, "sell" => 0.42],
            "dark_oak_planks" => ["item" => VanillaBlocks::DARK_OAK_PLANKS()->asItem(), "buy" => 5, "sell" => 0.42],
            "mangrove_planks" => ["item" => VanillaBlocks::MANGROVE_PLANKS()->asItem(), "buy" => 5, "sell" => 0.42],
            "cherry_planks" => ["item" => VanillaBlocks::CHERRY_PLANKS()->asItem(), "buy" => 5, "sell" => 0.42],
            "crimson_planks" => ["item" => VanillaBlocks::CRIMSON_PLANKS()->asItem(), "buy" => 10, "sell" => 0.83],
            "warped_planks" => ["item" => VanillaBlocks::WARPED_PLANKS()->asItem(), "buy" => 10, "sell" => 0.83],
            "oak_log" => ["item" => VanillaBlocks::OAK_LOG()->asItem(), "buy" => 10, "sell" => 0.83],
            "spruce_log" => ["item" => VanillaBlocks::SPRUCE_LOG()->asItem(), "buy" => 10, "sell" => 0.83],
            "birch_log" => ["item" => VanillaBlocks::BIRCH_LOG()->asItem(), "buy" => 10, "sell" => 0.83],
            "jungle_log" => ["item" => VanillaBlocks::JUNGLE_LOG()->asItem(), "buy" => 10, "sell" => 0.83],
            "acacia_log" => ["item" => VanillaBlocks::ACACIA_LOG()->asItem(), "buy" => 10, "sell" => 0.83],
            "dark_oak_log" => ["item" => VanillaBlocks::DARK_OAK_LOG()->asItem(), "buy" => 10, "sell" => 0.83],
            "mangrove_log" => ["item" => VanillaBlocks::MANGROVE_LOG()->asItem(), "buy" => 10, "sell" => 0.83],
            "cherry_log" => ["item" => VanillaBlocks::CHERRY_LOG()->asItem(), "buy" => 10, "sell" => 0.83],
            "crimson_stem" => ["item" => VanillaBlocks::CRIMSON_STEM()->asItem(), "buy" => 15, "sell" => 1.25],
            "warped_stem" => ["item" => VanillaBlocks::WARPED_STEM()->asItem(), "buy" => 15, "sell" => 1.25],
            "coal_ore" => ["item" => VanillaBlocks::COAL_ORE()->asItem(), "buy" => 75, "sell" => 6.25],
            "iron_ore" => ["item" => VanillaBlocks::IRON_ORE()->asItem(), "buy" => 125, "sell" => 10.42],
            "gold_ore" => ["item" => VanillaBlocks::GOLD_ORE()->asItem(), "buy" => 175, "sell" => 14.58],
            "diamond_ore" => ["item" => VanillaBlocks::DIAMOND_ORE()->asItem(), "buy" => 400, "sell" => 33.33],
            "emerald_ore" => ["item" => VanillaBlocks::EMERALD_ORE()->asItem(), "buy" => 350, "sell" => 29.17],
            "lapis_ore" => ["item" => VanillaBlocks::LAPIS_LAZULI_ORE()->asItem(), "buy" => 100, "sell" => 8.33],
            "redstone_ore" => ["item" => VanillaBlocks::REDSTONE_ORE()->asItem(), "buy" => 90, "sell" => 7.50],
            "nether_quartz_ore" => ["item" => VanillaBlocks::NETHER_QUARTZ_ORE()->asItem(), "buy" => 100, "sell" => 8.33],
            "nether_gold_ore" => ["item" => VanillaBlocks::NETHER_GOLD_ORE()->asItem(), "buy" => 125, "sell" => 10.42],
            "netherrack" => ["item" => VanillaBlocks::NETHERRACK()->asItem(), "buy" => 10, "sell" => 0.83],
            "soul_sand" => ["item" => VanillaBlocks::SOUL_SAND()->asItem(), "buy" => 15, "sell" => 1.25],
            "soul_soil" => ["item" => VanillaBlocks::SOUL_SOIL()->asItem(), "buy" => 15, "sell" => 1.25],
            "nether_bricks" => ["item" => VanillaBlocks::NETHER_BRICKS()->asItem(), "buy" => 20, "sell" => 1.67],
            "red_nether_bricks" => ["item" => VanillaBlocks::RED_NETHER_BRICKS()->asItem(), "buy" => 25, "sell" => 2.08],
            "basalt" => ["item" => VanillaBlocks::BASALT()->asItem(), "buy" => 15, "sell" => 1.25],
            "blackstone" => ["item" => VanillaBlocks::BLACKSTONE()->asItem(), "buy" => 20, "sell" => 1.67],
            "polished_blackstone" => ["item" => VanillaBlocks::POLISHED_BLACKSTONE()->asItem(), "buy" => 25, "sell" => 2.08],
            "polished_basalt" => ["item" => VanillaBlocks::POLISHED_BASALT()->asItem(), "buy" => 20, "sell" => 1.67],
            "chiseled_nether_bricks" => ["item" => VanillaBlocks::CHISELED_NETHER_BRICKS()->asItem(), "buy" => 30, "sell" => 2.50],
            "end_stone" => ["item" => VanillaBlocks::END_STONE()->asItem(), "buy" => 50, "sell" => 4.17],
            "end_stone_bricks" => ["item" => VanillaBlocks::END_STONE_BRICKS()->asItem(), "buy" => 60, "sell" => 5.00],
            "purpur_block" => ["item" => VanillaBlocks::PURPUR()->asItem(), "buy" => 40, "sell" => 3.33],
            "bricks" => ["item" => VanillaBlocks::BRICKS()->asItem(), "buy" => 15, "sell" => 1.25],
            "stone_bricks" => ["item" => VanillaBlocks::STONE_BRICKS()->asItem(), "buy" => 15, "sell" => 1.25],
            "mossy_cobblestone" => ["item" => VanillaBlocks::MOSSY_COBBLESTONE()->asItem(), "buy" => 15, "sell" => 1.25],
            "mossy_stone_bricks" => ["item" => VanillaBlocks::MOSSY_STONE_BRICKS()->asItem(), "buy" => 15, "sell" => 1.25],
            "sandstone" => ["item" => VanillaBlocks::SANDSTONE()->asItem(), "buy" => 10, "sell" => 0.83],
            "red_sandstone" => ["item" => VanillaBlocks::RED_SANDSTONE()->asItem(), "buy" => 10, "sell" => 0.83],
            "prismarine" => ["item" => VanillaBlocks::PRISMARINE()->asItem(), "buy" => 25, "sell" => 2.08],
            "dark_prismarine" => ["item" => VanillaBlocks::DARK_PRISMARINE()->asItem(), "buy" => 30, "sell" => 2.50],
            "quartz_block" => ["item" => VanillaBlocks::QUARTZ()->asItem(), "buy" => 35, "sell" => 2.92],
            "smooth_quartz" => ["item" => VanillaBlocks::SMOOTH_QUARTZ()->asItem(), "buy" => 40, "sell" => 3.33],
            "chiseled_quartz" => ["item" => VanillaBlocks::CHISELED_QUARTZ()->asItem(), "buy" => 40, "sell" => 3.33],
            "quartz_pillar" => ["item" => VanillaBlocks::QUARTZ_PILLAR()->asItem(), "buy" => 40, "sell" => 3.33],
            "smooth_sandstone" => ["item" => VanillaBlocks::SMOOTH_SANDSTONE()->asItem(), "buy" => 15, "sell" => 1.25],
            "smooth_red_sandstone" => ["item" => VanillaBlocks::SMOOTH_RED_SANDSTONE()->asItem(), "buy" => 15, "sell" => 1.25],
            "cut_sandstone" => ["item" => VanillaBlocks::CUT_SANDSTONE()->asItem(), "buy" => 15, "sell" => 1.25],
            "cut_red_sandstone" => ["item" => VanillaBlocks::CUT_RED_SANDSTONE()->asItem(), "buy" => 15, "sell" => 1.25],
            "andesite" => ["item" => VanillaBlocks::ANDESITE()->asItem(), "buy" => 10, "sell" => 0.83],
            "diorite" => ["item" => VanillaBlocks::DIORITE()->asItem(), "buy" => 10, "sell" => 0.83],
            "granite" => ["item" => VanillaBlocks::GRANITE()->asItem(), "buy" => 10, "sell" => 0.83],
            "polished_andesite" => ["item" => VanillaBlocks::POLISHED_ANDESITE()->asItem(), "buy" => 15, "sell" => 1.25],
            "polished_diorite" => ["item" => VanillaBlocks::POLISHED_DIORITE()->asItem(), "buy" => 15, "sell" => 1.25],
            "polished_granite" => ["item" => VanillaBlocks::POLISHED_GRANITE()->asItem(), "buy" => 15, "sell" => 1.25],
            "hardened_clay" => ["item" => VanillaBlocks::HARDENED_CLAY()->asItem(), "buy" => 20, "sell" => 1.67],
            "white_glazed_terracotta" => ["item" => VanillaBlocks::GLAZED_TERRACOTTA()->setColor(DyeColor::WHITE())->asItem(), "buy" => 25, "sell" => 2.08],
            "orange_glazed_terracotta" => ["item" => VanillaBlocks::GLAZED_TERRACOTTA()->setColor(DyeColor::ORANGE())->asItem(), "buy" => 25, "sell" => 2.08],
            "yellow_glazed_terracotta" => ["item" => VanillaBlocks::GLAZED_TERRACOTTA()->setColor(DyeColor::YELLOW())->asItem(), "buy" => 25, "sell" => 2.08],
            "white_concrete" => ["item" => VanillaBlocks::CONCRETE()->setColor(DyeColor::WHITE())->asItem(), "buy" => 20, "sell" => 1.67],
            "black_concrete" => ["item" => VanillaBlocks::CONCRETE()->setColor(DyeColor::BLACK())->asItem(), "buy" => 20, "sell" => 1.67],
            "red_concrete" => ["item" => VanillaBlocks::CONCRETE()->setColor(DyeColor::RED())->asItem(), "buy" => 20, "sell" => 1.67],
            "white_wool" => ["item" => VanillaBlocks::WOOL()->setColor(DyeColor::WHITE())->asItem(), "buy" => 15, "sell" => 1.25],
            "black_wool" => ["item" => VanillaBlocks::WOOL()->setColor(DyeColor::BLACK())->asItem(), "buy" => 15, "sell" => 1.25],
            "red_wool" => ["item" => VanillaBlocks::WOOL()->setColor(DyeColor::RED())->asItem(), "buy" => 15, "sell" => 1.25],
            "obsidian" => ["item" => VanillaBlocks::OBSIDIAN()->asItem(), "buy" => 200, "sell" => 16.67],
            "glowstone" => ["item" => VanillaBlocks::GLOWSTONE()->asItem(), "buy" => 75, "sell" => 6.25],
            "sea_lantern" => ["item" => VanillaBlocks::SEA_LANTERN()->asItem(), "buy" => 100, "sell" => 8.33],
            "magma" => ["item" => VanillaBlocks::MAGMA()->asItem(), "buy" => 50, "sell" => 4.17],
            "bookshelf" => ["item" => VanillaBlocks::BOOKSHELF()->asItem(), "buy" => 50, "sell" => 4.17],
            "cobweb" => ["item" => VanillaBlocks::COBWEB()->asItem(), "buy" => 25, "sell" => 2.08],
            "ice" => ["item" => VanillaBlocks::ICE()->asItem(), "buy" => 25, "sell" => 2.08],
            "packed_ice" => ["item" => VanillaBlocks::PACKED_ICE()->asItem(), "buy" => 40, "sell" => 3.33],
            "blue_ice" => ["item" => VanillaBlocks::BLUE_ICE()->asItem(), "buy" => 60, "sell" => 5.00],
            "bone_block" => ["item" => VanillaBlocks::BONE_BLOCK()->asItem(), "buy" => 40, "sell" => 3.33],
            "hay_bale" => ["item" => VanillaBlocks::HAY_BALE()->asItem(), "buy" => 30, "sell" => 2.50],
            "slime_block" => ["item" => VanillaBlocks::SLIME()->asItem(), "buy" => 75, "sell" => 6.25],
            "dried_kelp_block" => ["item" => VanillaBlocks::DRIED_KELP()->asItem(), "buy" => 25, "sell" => 2.08],
            "emerald_block" => ["item" => VanillaBlocks::EMERALD()->asItem(), "buy" => 2700, "sell" => 225.00],
            "diamond_block" => ["item" => VanillaBlocks::DIAMOND()->asItem(), "buy" => 3150, "sell" => 262.50],
            "gold_block" => ["item" => VanillaBlocks::GOLD()->asItem(), "buy" => 1350, "sell" => 112.50],
            "iron_block" => ["item" => VanillaBlocks::IRON()->asItem(), "buy" => 900, "sell" => 75.00],
            "copper_block" => ["item" => VanillaBlocks::COPPER()->asItem(), "buy" => 150, "sell" => 12.50],
            "lapis_block" => ["item" => VanillaBlocks::LAPIS_LAZULI()->asItem(), "buy" => 540, "sell" => 45.00],
            "redstone_block" => ["item" => VanillaBlocks::REDSTONE()->asItem(), "buy" => 360, "sell" => 30.00],
            "coal_block" => ["item" => VanillaBlocks::COAL()->asItem(), "buy" => 450, "sell" => 37.50],
            "amethyst_block" => ["item" => VanillaBlocks::AMETHYST()->asItem(), "buy" => 200, "sell" => 16.67],
            "tuff" => ["item" => VanillaBlocks::TUFF()->asItem(), "buy" => 10, "sell" => 0.83],
            "deepslate" => ["item" => VanillaBlocks::DEEPSLATE()->asItem(), "buy" => 10, "sell" => 0.83],
            "honeycomb_block" => ["item" => VanillaBlocks::HONEYCOMB()->asItem(), "buy" => 50, "sell" => 4.17],
            "sculk" => ["item" => VanillaBlocks::SCULK()->asItem(), "buy" => 50, "sell" => 4.17],
            "crafting_table" => ["item" => VanillaBlocks::CRAFTING_TABLE()->asItem(), "buy" => 25, "sell" => 2.08],
            "furnace" => ["item" => VanillaBlocks::FURNACE()->asItem(), "buy" => 50, "sell" => 4.17],
            "chest" => ["item" => VanillaBlocks::CHEST()->asItem(), "buy" => 40, "sell" => 3.33],
            "barrel" => ["item" => VanillaBlocks::BARREL()->asItem(), "buy" => 40, "sell" => 3.33],
            "glass" => ["item" => VanillaBlocks::GLASS()->asItem(), "buy" => 15, "sell" => 1.25],
            "white_stained_glass" => ["item" => VanillaBlocks::STAINED_GLASS()->setColor(DyeColor::WHITE())->asItem(), "buy" => 20, "sell" => 1.67],
            "black_stained_glass" => ["item" => VanillaBlocks::STAINED_GLASS()->setColor(DyeColor::BLACK())->asItem(), "buy" => 20, "sell" => 1.67],
            "red_stained_glass" => ["item" => VanillaBlocks::STAINED_GLASS()->setColor(DyeColor::RED())->asItem(), "buy" => 20, "sell" => 1.67],
            "blue_stained_glass" => ["item" => VanillaBlocks::STAINED_GLASS()->setColor(DyeColor::BLUE())->asItem(), "buy" => 20, "sell" => 1.67],
            "green_stained_glass" => ["item" => VanillaBlocks::STAINED_GLASS()->setColor(DyeColor::GREEN())->asItem(), "buy" => 20, "sell" => 1.67],
            "yellow_stained_glass" => ["item" => VanillaBlocks::STAINED_GLASS()->setColor(DyeColor::YELLOW())->asItem(), "buy" => 20, "sell" => 1.67],
            "cyan_stained_glass" => ["item" => VanillaBlocks::STAINED_GLASS()->setColor(DyeColor::CYAN())->asItem(), "buy" => 20, "sell" => 1.67],
            "purple_stained_glass" => ["item" => VanillaBlocks::STAINED_GLASS()->setColor(DyeColor::PURPLE())->asItem(), "buy" => 20, "sell" => 1.67],
            "pink_stained_glass" => ["item" => VanillaBlocks::STAINED_GLASS()->setColor(DyeColor::PINK())->asItem(), "buy" => 20, "sell" => 1.67],
        ],
        "tools" => [
            "wooden_pickaxe" => ["item" => $parser->parse("wooden_pickaxe") ?? $fallbackItem, "buy" => 100, "sell" => 4.17],
            "stone_pickaxe" => ["item" => $parser->parse("stone_pickaxe") ?? $fallbackItem, "buy" => 200, "sell" => 8.33],
            "iron_pickaxe" => ["item" => $parser->parse("iron_pickaxe") ?? $fallbackItem, "buy" => 750, "sell" => 31.25],
            "golden_pickaxe" => ["item" => $parser->parse("golden_pickaxe") ?? $fallbackItem, "buy" => 1000, "sell" => 41.67],
            "diamond_pickaxe" => ["item" => $parser->parse("diamond_pickaxe") ?? $fallbackItem, "buy" => 2500, "sell" => 104.17],
            "wooden_axe" => ["item" => $parser->parse("wooden_axe") ?? $fallbackItem, "buy" => 75, "sell" => 3.13],
            "stone_axe" => ["item" => $parser->parse("stone_axe") ?? $fallbackItem, "buy" => 150, "sell" => 6.25],
            "iron_axe" => ["item" => $parser->parse("iron_axe") ?? $fallbackItem, "buy" => 600, "sell" => 25.00],
            "golden_axe" => ["item" => $parser->parse("golden_axe") ?? $fallbackItem, "buy" => 750, "sell" => 31.25],
            "diamond_axe" => ["item" => $parser->parse("diamond_axe") ?? $fallbackItem, "buy" => 2000, "sell" => 83.33],
            "wooden_shovel" => ["item" => $parser->parse("wooden_shovel") ?? $fallbackItem, "buy" => 75, "sell" => 3.13],
            "stone_shovel" => ["item" => $parser->parse("stone_shovel") ?? $fallbackItem, "buy" => 150, "sell" => 6.25],
            "iron_shovel" => ["item" => $parser->parse("iron_shovel") ?? $fallbackItem, "buy" => 600, "sell" => 25.00],
            "golden_shovel" => ["item" => $parser->parse("golden_shovel") ?? $fallbackItem, "buy" => 750, "sell" => 31.25],
            "diamond_shovel" => ["item" => $parser->parse("diamond_shovel") ?? $fallbackItem, "buy" => 2000, "sell" => 83.33],
            "wooden_hoe" => ["item" => $parser->parse("wooden_hoe") ?? $fallbackItem, "buy" => 75, "sell" => 3.13],
            "stone_hoe" => ["item" => $parser->parse("stone_hoe") ?? $fallbackItem, "buy" => 150, "sell" => 6.25],
            "iron_hoe" => ["item" => $parser->parse("iron_hoe") ?? $fallbackItem, "buy" => 600, "sell" => 25.00],
            "golden_hoe" => ["item" => $parser->parse("golden_hoe") ?? $fallbackItem, "buy" => 750, "sell" => 31.25],
            "diamond_hoe" => ["item" => $parser->parse("diamond_hoe") ?? $fallbackItem, "buy" => 2000, "sell" => 83.33],
            "wooden_sword" => ["item" => $parser->parse("wooden_sword") ?? $fallbackItem, "buy" => 125, "sell" => 5.21],
            "stone_sword" => ["item" => $parser->parse("stone_sword") ?? $fallbackItem, "buy" => 250, "sell" => 10.42],
            "iron_sword" => ["item" => $parser->parse("iron_sword") ?? $fallbackItem, "buy" => 1000, "sell" => 41.67],
            "golden_sword" => ["item" => $parser->parse("golden_sword") ?? $fallbackItem, "buy" => 1250, "sell" => 52.08],
            "diamond_sword" => ["item" => $parser->parse("diamond_sword") ?? $fallbackItem, "buy" => 3000, "sell" => 125.00],
            "leather_helmet" => ["item" => $parser->parse("leather_helmet") ?? $fallbackItem, "buy" => 200, "sell" => 8.33],
            "leather_chestplate" => ["item" => $parser->parse("leather_chestplate") ?? $fallbackItem, "buy" => 350, "sell" => 14.58],
            "leather_leggings" => ["item" => $parser->parse("leather_leggings") ?? $fallbackItem, "buy" => 300, "sell" => 12.50],
            "leather_boots" => ["item" => $parser->parse("leather_boots") ?? $fallbackItem, "buy" => 150, "sell" => 6.25],
            "iron_helmet" => ["item" => $parser->parse("iron_helmet") ?? $fallbackItem, "buy" => 500, "sell" => 20.83],
            "iron_chestplate" => ["item" => $parser->parse("iron_chestplate") ?? $fallbackItem, "buy" => 1000, "sell" => 41.67],
            "iron_leggings" => ["item" => $parser->parse("iron_leggings") ?? $fallbackItem, "buy" => 900, "sell" => 37.50],
            "iron_boots" => ["item" => $parser->parse("iron_boots") ?? $fallbackItem, "buy" => 400, "sell" => 16.67],
            "diamond_helmet" => ["item" => $parser->parse("diamond_helmet") ?? $fallbackItem, "buy" => 1500, "sell" => 62.50],
            "diamond_chestplate" => ["item" => $parser->parse("diamond_chestplate") ?? $fallbackItem, "buy" => 3000, "sell" => 125.00],
            "diamond_leggings" => ["item" => $parser->parse("diamond_leggings") ?? $fallbackItem, "buy" => 2500, "sell" => 104.17],
            "diamond_boots" => ["item" => $parser->parse("diamond_boots") ?? $fallbackItem, "buy" => 1500, "sell" => 62.50],
            "chainmail_helmet" => ["item" => $parser->parse("chainmail_helmet") ?? $fallbackItem, "buy" => 400, "sell" => 16.67],
            "chainmail_chestplate" => ["item" => $parser->parse("chainmail_chestplate") ?? $fallbackItem, "buy" => 750, "sell" => 31.25],
            "chainmail_leggings" => ["item" => $parser->parse("chainmail_leggings") ?? $fallbackItem, "buy" => 600, "sell" => 25.00],
            "chainmail_boots" => ["item" => $parser->parse("chainmail_boots") ?? $fallbackItem, "buy" => 300, "sell" => 12.50],
            "bow" => ["item" => $parser->parse("bow") ?? $fallbackItem, "buy" => 500, "sell" => 20.83],
            "arrow" => ["item" => ($parser->parse("arrow") ?? $fallbackItem)->setCount(16), "buy" => 50, "sell" => 2.08],
            "shears" => ["item" => $parser->parse("shears") ?? $fallbackItem, "buy" => 250, "sell" => 10.42],
            "flint_and_steel" => ["item" => $parser->parse("flint_and_steel") ?? $fallbackItem, "buy" => 150, "sell" => 6.25],
            "fishing_rod" => ["item" => $parser->parse("fishing_rod") ?? $fallbackItem, "buy" => 250, "sell" => 10.42],
            "compass" => ["item" => $parser->parse("compass") ?? $fallbackItem, "buy" => 150, "sell" => 6.25],
            "clock" => ["item" => $parser->parse("clock") ?? $fallbackItem, "buy" => 150, "sell" => 6.25],
            "bucket" => ["item" => $parser->parse("bucket") ?? $fallbackItem, "buy" => 100, "sell" => 4.17],
            "water_bucket" => ["item" => $parser->parse("water_bucket") ?? $fallbackItem, "buy" => 200, "sell" => 8.33],
            "lava_bucket" => ["item" => $parser->parse("lava_bucket") ?? $fallbackItem, "buy" => 500, "sell" => 20.83],
            "name_tag" => ["item" => $parser->parse("name_tag") ?? $fallbackItem, "buy" => 250, "sell" => 10.42],
            "spyglass" => ["item" => $parser->parse("spyglass") ?? $fallbackItem, "buy" => 200, "sell" => 8.33],
            "goat_horn" => ["item" => $parser->parse("goat_horn") ?? $fallbackItem, "buy" => 300, "sell" => 12.50],
            "firework_rocket" => ["item" => $parser->parse("firework_rocket") ?? $fallbackItem, "buy" => 50, "sell" => 2.08],
        ],
        "food" => [
            "apple" => ["item" => $parser->parse("apple") ?? $fallbackItem, "buy" => 25, "sell" => 2.08],
            "bread" => ["item" => $parser->parse("bread") ?? $fallbackItem, "buy" => 20, "sell" => 1.67],
            "steak" => ["item" => $parser->parse("cooked_beef") ?? $fallbackItem, "buy" => 40, "sell" => 3.33],
            "cooked_chicken" => ["item" => $parser->parse("cooked_chicken") ?? $fallbackItem, "buy" => 30, "sell" => 2.50],
            "cooked_porkchop" => ["item" => $parser->parse("cooked_porkchop") ?? $fallbackItem, "buy" => 35, "sell" => 2.92],
            "cooked_mutton" => ["item" => $parser->parse("cooked_mutton") ?? $fallbackItem, "buy" => 30, "sell" => 2.50],
            "cooked_salmon" => ["item" => $parser->parse("cooked_salmon") ?? $fallbackItem, "buy" => 30, "sell" => 2.50],
            "cooked_cod" => ["item" => $parser->parse("cooked_cod") ?? $fallbackItem, "buy" => 30, "sell" => 2.50],
            "carrot" => ["item" => $parser->parse("carrot") ?? $fallbackItem, "buy" => 15, "sell" => 1.25],
            "potato" => ["item" => $parser->parse("potato") ?? $fallbackItem, "buy" => 10, "sell" => 0.83],
            "baked_potato" => ["item" => $parser->parse("baked_potato") ?? $fallbackItem, "buy" => 20, "sell" => 1.67],
            "pumpkin_pie" => ["item" => $parser->parse("pumpkin_pie") ?? $fallbackItem, "buy" => 50, "sell" => 4.17],
            "melon_slice" => ["item" => $parser->parse("melon") ?? $fallbackItem, "buy" => 10, "sell" => 0.83],
            "cookie" => ["item" => ($parser->parse("cookie") ?? $fallbackItem)->setCount(8), "buy" => 25, "sell" => 2.08],
            "beetroot" => ["item" => $parser->parse("beetroot") ?? $fallbackItem, "buy" => 15, "sell" => 1.25],
            "beetroot_soup" => ["item" => $parser->parse("beetroot_soup") ?? $fallbackItem, "buy" => 40, "sell" => 3.33],
            "mushroom_stew" => ["item" => $parser->parse("mushroom_stew") ?? $fallbackItem, "buy" => 40, "sell" => 3.33],
            "rabbit_stew" => ["item" => $parser->parse("rabbit_stew") ?? $fallbackItem, "buy" => 60, "sell" => 5.00],
            "golden_apple" => ["item" => $parser->parse("golden_apple") ?? $fallbackItem, "buy" => 250, "sell" => 20.83],
            "golden_carrot" => ["item" => $parser->parse("golden_carrot") ?? $fallbackItem, "buy" => 100, "sell" => 8.33],
            "wheat_seeds" => ["item" => ($parser->parse("wheat_seeds") ?? $fallbackItem)->setCount(16), "buy" => 15, "sell" => 1.25],
            "pumpkin_seeds" => ["item" => ($parser->parse("pumpkin_seeds") ?? $fallbackItem)->setCount(16), "buy" => 15, "sell" => 1.25],
            "melon_seeds" => ["item" => ($parser->parse("melon_seeds") ?? $fallbackItem)->setCount(16), "buy" => 15, "sell" => 1.25],
            "beetroot_seeds" => ["item" => ($parser->parse("beetroot_seeds") ?? $fallbackItem)->setCount(16), "buy" => 15, "sell" => 1.25],
            "wheat" => ["item" => $parser->parse("wheat") ?? $fallbackItem, "buy" => 10, "sell" => 0.83],
            "sugar_cane" => ["item" => $parser->parse("sugar_cane") ?? $fallbackItem, "buy" => 20, "sell" => 1.67],
            "egg" => ["item" => ($parser->parse("egg") ?? $fallbackItem)->setCount(4), "buy" => 25, "sell" => 2.08],
            "milk_bucket" => ["item" => $parser->parse("milk_bucket") ?? $fallbackItem, "buy" => 75, "sell" => 6.25],
            "cake" => ["item" => $parser->parse("cake") ?? $fallbackItem, "buy" => 150, "sell" => 12.50],
            "sweet_berries" => ["item" => ($parser->parse("sweet_berries") ?? $fallbackItem)->setCount(8), "buy" => 20, "sell" => 1.67],
            "glow_berries" => ["item" => ($parser->parse("glow_berries") ?? $fallbackItem)->setCount(8), "buy" => 30, "sell" => 2.50],
            "cocoa_beans" => ["item" => ($parser->parse("cocoa_beans") ?? $fallbackItem)->setCount(8), "buy" => 25, "sell" => 2.08],
            "suspicious_stew" => ["item" => $parser->parse("suspicious_stew") ?? $fallbackItem, "buy" => 60, "sell" => 5.00],
            "honey_bottle" => ["item" => $parser->parse("honey_bottle") ?? $fallbackItem, "buy" => 75, "sell" => 6.25],
            "dried_kelp" => ["item" => $parser->parse("dried_kelp") ?? $fallbackItem, "buy" => 15, "sell" => 1.25],
            "rabbit" => ["item" => $parser->parse("rabbit") ?? $fallbackItem, "buy" => 25, "sell" => 2.08],
            "cooked_rabbit" => ["item" => $parser->parse("cooked_rabbit") ?? $fallbackItem, "buy" => 35, "sell" => 2.92],
        ],
        "misc" => [
            "coal" => ["item" => $parser->parse("coal") ?? $fallbackItem, "buy" => 50, "sell" => 4.17],
            "charcoal" => ["item" => $parser->parse("charcoal") ?? $fallbackItem, "buy" => 50, "sell" => 4.17],
            "iron_ingot" => ["item" => $parser->parse("iron_ingot") ?? $fallbackItem, "buy" => 100, "sell" => 8.33],
            "gold_ingot" => ["item" => $parser->parse("gold_ingot") ?? $fallbackItem, "buy" => 150, "sell" => 12.50],
            "diamond" => ["item" => $parser->parse("diamond") ?? $fallbackItem, "buy" => 350, "sell" => 29.17],
            "emerald" => ["item" => $parser->parse("emerald") ?? $fallbackItem, "buy" => 300, "sell" => 25.00],
            "redstone" => ["item" => ($parser->parse("redstone") ?? $fallbackItem)->setCount(4), "buy" => 40, "sell" => 3.33],
            "lapis_lazuli" => ["item" => ($parser->parse("lapis_lazuli") ?? $fallbackItem)->setCount(4), "buy" => 60, "sell" => 5.00],
            "nether_quartz" => ["item" => $parser->parse("nether_quartz") ?? $fallbackItem, "buy" => 60, "sell" => 5.00],
            "copper_ingot" => ["item" => $parser->parse("copper_ingot") ?? $fallbackItem, "buy" => 75, "sell" => 6.25],
            "bone" => ["item" => ($parser->parse("bone") ?? $fallbackItem)->setCount(8), "buy" => 30, "sell" => 2.50],
            "string" => ["item" => ($parser->parse("string") ?? $fallbackItem)->setCount(8), "buy" => 25, "sell" => 2.08],
            "feather" => ["item" => ($parser->parse("feather") ?? $fallbackItem)->setCount(8), "buy" => 20, "sell" => 1.67],
            "leather" => ["item" => $parser->parse("leather") ?? $fallbackItem, "buy" => 15, "sell" => 1.25],
            "slimeball" => ["item" => $parser->parse("slimeball") ?? $fallbackItem, "buy" => 75, "sell" => 6.25],
            "ender_pearl" => ["item" => $parser->parse("ender_pearl") ?? $fallbackItem, "buy" => 200, "sell" => 16.67],
            "blaze_rod" => ["item" => $parser->parse("blaze_rod") ?? $fallbackItem, "buy" => 250, "sell" => 20.83],
            "ghast_tear" => ["item" => $parser->parse("ghast_tear") ?? $fallbackItem, "buy" => 300, "sell" => 25.00],
            "gunpowder" => ["item" => ($parser->parse("gunpowder") ?? $fallbackItem)->setCount(4), "buy" => 40, "sell" => 3.33],
            "spider_eye" => ["item" => $parser->parse("spider_eye") ?? $fallbackItem, "buy" => 50, "sell" => 4.17],
            "fermented_spider_eye" => ["item" => $parser->parse("fermented_spider_eye") ?? $fallbackItem, "buy" => 75, "sell" => 6.25],
            "magma_cream" => ["item" => $parser->parse("magma_cream") ?? $fallbackItem, "buy" => 100, "sell" => 8.33],
            "phantom_membrane" => ["item" => $parser->parse("phantom_membrane") ?? $fallbackItem, "buy" => 125, "sell" => 10.42],
            "shulker_shell" => ["item" => $parser->parse("shulker_shell") ?? $fallbackItem, "buy" => 400, "sell" => 33.33],
            "nautilus_shell" => ["item" => $parser->parse("nautilus_shell") ?? $fallbackItem, "buy" => 200, "sell" => 16.67],
            "flint" => ["item" => $parser->parse("flint") ?? $fallbackItem, "buy" => 25, "sell" => 2.08],
            "paper" => ["item" => ($parser->parse("paper") ?? $fallbackItem)->setCount(8), "buy" => 30, "sell" => 2.50],
            "book" => ["item" => $parser->parse("book") ?? $fallbackItem, "buy" => 50, "sell" => 4.17],
            "glowstone_dust" => ["item" => ($parser->parse("glowstone_dust") ?? $fallbackItem)->setCount(4), "buy" => 40, "sell" => 3.33],
            "sugar" => ["item" => $parser->parse("sugar") ?? $fallbackItem, "buy" => 20, "sell" => 1.67],
            "honeycomb" => ["item" => $parser->parse("honeycomb") ?? $fallbackItem, "buy" => 40, "sell" => 3.33],
            "prismarine_shard" => ["item" => $parser->parse("prismarine_shard") ?? $fallbackItem, "buy" => 50, "sell" => 4.17],
            "prismarine_crystals" => ["item" => $parser->parse("prismarine_crystals") ?? $fallbackItem, "buy" => 75, "sell" => 6.25],
            "potion" => ["item" => $parser->parse("potion") ?? $fallbackItem, "buy" => 100, "sell" => 8.33],
            "splash_potion" => ["item" => $parser->parse("splash_potion") ?? $fallbackItem, "buy" => 150, "sell" => 12.50],
            "dragon_breath" => ["item" => $parser->parse("dragon_breath") ?? $fallbackItem, "buy" => 250, "sell" => 20.83],
            "blaze_powder" => ["item" => $parser->parse("blaze_powder") ?? $fallbackItem, "buy" => 75, "sell" => 6.25],
            "brewing_stand" => ["item" => VanillaBlocks::BREWING_STAND()->asItem(), "buy" => 250, "sell" => 20.83],
            "cauldron" => ["item" => VanillaBlocks::CAULDRON()->asItem(), "buy" => 150, "sell" => 12.50],
            "enchanting_table" => ["item" => VanillaBlocks::ENCHANTING_TABLE()->asItem(), "buy" => 500, "sell" => 41.67],
            "anvil" => ["item" => VanillaBlocks::ANVIL()->asItem(), "buy" => 600, "sell" => 50.00],
            "amethyst_shard" => ["item" => $parser->parse("amethyst_shard") ?? $fallbackItem, "buy" => 50, "sell" => 4.17],
            "netherite_ingot" => ["item" => $parser->parse("netherite_ingot") ?? $fallbackItem, "buy" => 500, "sell" => 41.67],
            "enchanted_book" => ["item" => $parser->parse("enchanted_book") ?? $fallbackItem, "buy" => 250, "sell" => 20.83],
            "experience_bottle" => ["item" => $parser->parse("experience_bottle") ?? $fallbackItem, "buy" => 500, "sell" => 41.67],
            "white_dye" => ["item" => ($parser->parse("white_dye") ?? $fallbackItem)->setCount(8), "buy" => 30, "sell" => 2.50],
            "black_dye" => ["item" => ($parser->parse("black_dye") ?? $fallbackItem)->setCount(8), "buy" => 30, "sell" => 2.50],
            "red_dye" => ["item" => ($parser->parse("red_dye") ?? $fallbackItem)->setCount(8), "buy" => 30, "sell" => 2.50],
            "white_banner" => ["item" => $parser->parse("white_banner") ?? $fallbackItem, "buy" => 75, "sell" => 6.25],
            "item_frame" => ["item" => $parser->parse("item_frame") ?? $fallbackItem, "buy" => 50, "sell" => 4.17],
            "flower_pot" => ["item" => $parser->parse("flower_pot") ?? $fallbackItem, "buy" => 25, "sell" => 2.08],
            "white_bed" => ["item" => $parser->parse("white_bed") ?? $fallbackItem, "buy" => 75, "sell" => 6.25],
            "black_bed" => ["item" => $parser->parse("black_bed") ?? $fallbackItem, "buy" => 75, "sell" => 6.25],
            "red_bed" => ["item" => $parser->parse("red_bed") ?? $fallbackItem, "buy" => 75, "sell" => 6.25],
            "torch" => ["item" => ($parser->parse("torch") ?? $fallbackItem)->setCount(8), "buy" => 20, "sell" => 1.67],
            "ladder" => ["item" => ($parser->parse("ladder") ?? $fallbackItem)->setCount(8), "buy" => 30, "sell" => 2.50],
            "oak_sign" => ["item" => $parser->parse("oak_sign") ?? $fallbackItem, "buy" => 25, "sell" => 2.08],
        ]
    ];
}

    public function open(Player $player): void {
        if (!$this->economy instanceof EconomyAPI) {
            $player->sendMessage(TextFormat::RED . "Economy system is not available!");
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
    
    
    $pageInfo = StringToItemParser::getInstance()->parse("paper")->setCustomName("§ePage " . ($this->currentPage + 1));
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
        
        if (isset(array_keys($items)[$index])) {
            $itemId = array_keys($items)[$index];
            $itemData = $items[$itemId];
            
            $displayItem = clone $itemData["item"];
            $displayItem->setCount(1); 
            $displayItem->setLore([
                "§r",
                "§7Buy Price: §a§l$" . $itemData["buy"] . "§r",
                "§7Sell Price: §6§l$" . $itemData["sell"] . "§r",
                "§r",
                "§eClick to view options"
            ]);
            
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

class ItemTransactionGui {
    private InvMenu $menu;
    private \pocketmine\plugin\Plugin $plugin;
    private ?EconomyAPI $economy;
    private Item $item;
    private float $buyPrice;
    private float $sellPrice;
    private string $itemId;
    private array $buyAmounts = [];
    private array $sellAmounts = [];

    private const BUY_1_SLOT = 10;
    private const BUY_32_SLOT = 11;
    private const BUY_64_SLOT = 12;
    private const SELL_1_SLOT = 14;
    private const SELL_32_SLOT = 15;
    private const SELL_64_SLOT = 16;
    private const CUSTOM_BUY_SLOT = 19;
    private const CUSTOM_SELL_SLOT = 20;
    private const CONFIRM_SLOT = 22;
    private const BACK_SLOT = 24;

    public function __construct(\pocketmine\plugin\Plugin $plugin, ?EconomyAPI $economy, Item $item, float $buyPrice, float $sellPrice, string $itemId) {
        $this->plugin = $plugin;
        $this->economy = $economy;
        $this->item = $item;
        $this->buyPrice = $buyPrice;
        $this->sellPrice = $sellPrice;
        $this->itemId = $itemId;
        
        $this->menu = InvMenu::create(InvMenu::TYPE_CHEST);
        $this->menu->setName("§l§e» §r§bItem Transaction §l§e«");
        
        $this->menu->setListener(function (InvMenuTransaction $transaction): InvMenuTransactionResult {
            $player = $transaction->getPlayer();
            $slot = $transaction->getAction()->getSlot();
            
            try {
                switch ($slot) {
                    case self::BUY_1_SLOT:
                        $this->incrementBuyAmount($player, 1);
                        $this->initInventory($player);
                        break;
                    case self::BUY_32_SLOT:
                        $this->incrementBuyAmount($player, 32);
                        $this->initInventory($player);
                        break;
                    case self::BUY_64_SLOT:
                        $this->incrementBuyAmount($player, 64);
                        $this->initInventory($player);
                        break;
                    case self::SELL_1_SLOT:
                        $this->incrementSellAmount($player, 1);
                        $this->initInventory($player);
                        break;
                    case self::SELL_32_SLOT:
                        $this->incrementSellAmount($player, 32);
                        $this->initInventory($player);
                        break;
                    case self::SELL_64_SLOT:
                        $this->incrementSellAmount($player, 64);
                        $this->initInventory($player);
                        break;
                    case self::CUSTOM_BUY_SLOT:
                        $player->removeCurrentWindow();
                        $this->openCustomAmountForm($player, true);
                        break;
                    case self::CUSTOM_SELL_SLOT:
                        $player->removeCurrentWindow();
                        $this->openCustomAmountForm($player, false);
                        break;
                    case self::CONFIRM_SLOT:
                        $player->removeCurrentWindow();
                        $this->handleConfirm($player);
                        break;
                    case self::BACK_SLOT:
                        $player->removeCurrentWindow();
                        $shopGui = new ShopGui($this->plugin, $this->economy);
                        $shopGui->open($player);
                        break;
                }
            } catch (\Exception $e) {
                $player->sendMessage(TextFormat::RED . "Error processing transaction: " . $e->getMessage());
            }
            
            return $transaction->discard();
        });
    }

    public function open(Player $player): void {
        if (!$this->economy instanceof EconomyAPI) {
            $player->sendMessage(TextFormat::RED . "Economy system is not available!");
            return;
        }
        $this->buyAmounts[$player->getName()] = 0;
        $this->sellAmounts[$player->getName()] = 0;
        $this->initInventory($player);
        $this->menu->send($player);
    }

    private function initInventory(Player $player): void {
        $inventory = $this->menu->getInventory();
        $inventory->clearAll();
        
        
        $background = VanillaBlocks::STAINED_GLASS_PANE()->setColor(DyeColor::GRAY())->asItem()->setCustomName(" ");
        for ($i = 0; $i < 27; $i++) {
            $inventory->setItem($i, $background);
        }
        
        
        $border = VanillaBlocks::STAINED_GLASS_PANE()->setColor(DyeColor::GRAY())->asItem()->setCustomName("§r§l§eTransaction Border");
        $borderSlots = [0,1,2,3,4,5,6,7,8,9,17,18,25,26];
        foreach ($borderSlots as $slot) {
            $inventory->setItem($slot, $border);
        }
        
        $playerName = $player->getName();
        $buyAmount = $this->buyAmounts[$playerName] ?? 0;
        $sellAmount = $this->sellAmounts[$playerName] ?? 0;
        $money = $this->economy instanceof EconomyAPI ? $this->economy->myMoney($player) : 0.0;
        
        $displayItem = clone $this->item;
        $displayItem->setCount(1); 
        $displayItem->setLore([
            "§r",
            "§7Buy Price: §a§l$" . $this->buyPrice . "§r §7each",
            "§7Sell Price: §6§l$" . $this->sellPrice . "§r §7each",
            "§r",
            "§7Your Money: §e§l$" . $money . "§r",
            "§7You Have: §b§l" . $this->countPlayerItems($player) . "x§r",
            "§r",
            "§7To Buy: §a§l" . $buyAmount . "x§r",
            "§7To Sell: §6§l" . $sellAmount . "x§r"
        ]);
        $inventory->setItem(13, $displayItem);
        
        $buy1 = StringToItemParser::getInstance()->parse("emerald")->setCustomName("§aBuy 1x")->setLore([
            "§7Cost: §a§l$" . $this->buyPrice * 1 . "§r",
            "§eClick to add"
        ]);
        $inventory->setItem(self::BUY_1_SLOT, $buy1);
        
        $buy32 = StringToItemParser::getInstance()->parse("emerald")->setCustomName("§aBuy 32x")->setLore([
            "§7Cost: §a§l$" . $this->buyPrice * 32 . "§r",
            "§eClick to add"
        ]);
        $inventory->setItem(self::BUY_32_SLOT, $buy32);
        
        $buy64 = StringToItemParser::getInstance()->parse("emerald")->setCustomName("§aBuy 64x")->setLore([
            "§7Cost: §a§l$" . $this->buyPrice * 64 . "§r",
            "§eClick to add"
        ]);
        $inventory->setItem(self::BUY_64_SLOT, $buy64);
        
        $sell1 = StringToItemParser::getInstance()->parse("gold_ingot")->setCustomName("§6Sell 1x")->setLore([
            "§7Profit: §6§l$" . $this->sellPrice * 1 . "§r",
            "§eClick to add"
        ]);
        $inventory->setItem(self::SELL_1_SLOT, $sell1);
        
        $sell32 = StringToItemParser::getInstance()->parse("gold_ingot")->setCustomName("§6Sell 32x")->setLore([
            "§7Profit: §6§l$" . $this->sellPrice * 32 . "§r",
            "§eClick to add"
        ]);
        $inventory->setItem(self::SELL_32_SLOT, $sell32);
        
        $sell64 = StringToItemParser::getInstance()->parse("gold_ingot")->setCustomName("§6Sell 64x")->setLore([
            "§7Profit: §6§l$" . $this->sellPrice * 64 . "§r",
            "§eClick to add"
        ]);
        $inventory->setItem(self::SELL_64_SLOT, $sell64);
        
        $customBuy = StringToItemParser::getInstance()->parse("book")->setCustomName("§bCustom Buy")->setLore([
            "§7Buy custom amount",
            "§eClick to set"
        ]);
        $inventory->setItem(self::CUSTOM_BUY_SLOT, $customBuy);
        
        $customSell = StringToItemParser::getInstance()->parse("book")->setCustomName("§bCustom Sell")->setLore([
            "§7Sell custom amount",
            "§eClick to set"
        ]);
        $inventory->setItem(self::CUSTOM_SELL_SLOT, $customSell);
        
        
        if ($buyAmount > 0 || $sellAmount > 0) {
            $confirmLore = [];
            if ($buyAmount > 0) {
                $confirmLore[] = "§7Buy: §a" . $buyAmount . "x for §l$" . ($buyAmount * $this->buyPrice) . "§r";
            }
            if ($sellAmount > 0) {
                $confirmLore[] = "§7Sell: §6" . $sellAmount . "x for §l$" . ($sellAmount * $this->sellPrice) . "§r";
            }
            $confirmLore[] = "§eClick to confirm";
            
            $confirm = StringToItemParser::getInstance()->parse("lime_wool")->setCustomName("§aConfirm Transaction")->setLore($confirmLore);
            $inventory->setItem(self::CONFIRM_SLOT, $confirm);
        }
        
        $back = StringToItemParser::getInstance()->parse("arrow")->setCustomName("§cBack to Bazaar");
        $inventory->setItem(self::BACK_SLOT, $back);
    }

    private function incrementBuyAmount(Player $player, int $amount): void {
        $playerName = $player->getName();
        $this->buyAmounts[$playerName] = ($this->buyAmounts[$playerName] ?? 0) + $amount;
        $this->sellAmounts[$playerName] = 0; 
    }

    private function incrementSellAmount(Player $player, int $amount): void {
        $playerName = $player->getName();
        $this->sellAmounts[$playerName] = ($this->sellAmounts[$playerName] ?? 0) + $amount;
        $this->buyAmounts[$playerName] = 0; 
    }

    
    private function setBuyAmount(Player $player, int $amount): void {
        $playerName = $player->getName();
        $this->buyAmounts[$playerName] = $amount;
        $this->sellAmounts[$playerName] = 0;
    }

    
    private function setSellAmount(Player $player, int $amount): void {
        $playerName = $player->getName();
        $this->sellAmounts[$playerName] = $amount;
        $this->buyAmounts[$playerName] = 0;
    }

    private function handleConfirm(Player $player): void {
        $playerName = $player->getName();
        $buyAmount = $this->buyAmounts[$playerName] ?? 0;
        $sellAmount = $this->sellAmounts[$playerName] ?? 0;

        if ($buyAmount > 0) {
            $this->handleBuy($player, $buyAmount);
        } elseif ($sellAmount > 0) {
            $this->handleSell($player, $sellAmount);
        }

        $this->buyAmounts[$playerName] = 0;
        $this->sellAmounts[$playerName] = 0;
        $this->open($player);
    }

    private function handleBuy(Player $player, int $amount): void {
        if (!$this->economy instanceof EconomyAPI) {
            $player->sendMessage(TextFormat::RED . "Economy system is not available!");
            return;
        }

        $totalCost = $this->buyPrice * $amount;
        $money = $this->economy->myMoney($player);
        
        if ($money < $totalCost) {
            $player->sendMessage(TextFormat::RED . "You don't have enough money! Need: $" . $totalCost);
            return;
        }
        
        if (!$player->getInventory()->canAddItem($this->item->setCount($amount))) {
            $player->sendMessage(TextFormat::RED . "Not enough inventory space!");
            return;
        }
        
        $this->economy->reduceMoney($player, $totalCost);
        
        $item = clone $this->item;
        $item->setCount($amount);
        $player->getInventory()->addItem($item);
        
        $player->sendMessage(TextFormat::GREEN . "Bought " . $amount . "x " . $item->getName() . " for $" . $totalCost);
    }

    private function handleSell(Player $player, int $amount): void {
        if (!$this->economy instanceof EconomyAPI) {
            $player->sendMessage(TextFormat::RED . "Economy system is not available!");
            return;
        }

        $count = $this->countPlayerItems($player);
        
        if ($count < $amount) {
            $player->sendMessage(TextFormat::RED . "You don't have enough items! Need: " . $amount . "x");
            return;
        }
        
        $totalProfit = $this->sellPrice * $amount;
        $this->economy->addMoney($player, $totalProfit);
        $this->removePlayerItems($player, $amount);
        
        $player->sendMessage(TextFormat::GREEN . "Sold " . $amount . "x " . $this->item->getName() . " for $" . $totalProfit);
    }
private function openCustomAmountForm(Player $player, bool $isBuy): void {
    $playerName = $player->getName();
    $money = $this->economy instanceof EconomyAPI ? $this->economy->myMoney($player) : 0.0;
    $itemCount = $this->countPlayerItems($player);

    $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(
        function() use ($player, $isBuy, $money, $itemCount, $playerName): void {
            if (!$player->isOnline()) {
                $this->plugin->getLogger()->debug("Player $playerName is offline, aborting CustomForm");
                return;
            }

            $form = new CustomForm(function (Player $player, $data) use ($isBuy, $money, $itemCount): void {
                if ($data === null) {
                    $this->open($player);
                    return;
                }

                $amount = intval($data[0]);
                if ($amount <= 0) {
                    $player->sendMessage(TextFormat::RED . "Invalid amount!");
                    $this->open($player);
                    return;
                }

                if ($isBuy) {
                    $totalCost = $this->buyPrice * $amount;
                    if ($money < $totalCost) {
                        $player->sendMessage(TextFormat::RED . "You don't have enough money! Need: $" . $totalCost);
                        $this->open($player);
                        return;
                    }
                    $this->setBuyAmount($player, $amount);
                } else {
                    if ($itemCount < $amount) {
                        $player->sendMessage(TextFormat::RED . "You don't have enough items! Need: " . $amount . "x");
                        $this->open($player);
                        return;
                    }
                    $this->setSellAmount($player, $amount);
                }

                $this->initInventory($player);
                $this->menu->send($player);
            });

            $form->setTitle($isBuy ? "§e» §aBuy Amount §e«" : "§e» §cSell Amount §e«");
            $form->addInput("Amount", "Enter amount to " . ($isBuy ? "buy" : "sell"));
            $player->sendForm($form);
        }
    ), 10);
}

    private function countPlayerItems(Player $player): int {
        $count = 0;
        foreach ($player->getInventory()->getContents() as $item) {
            
            if ($item->equals($this->item, true, false)) {
                $count += $item->getCount();
            }
        }
        return $count;
    }

    private function removePlayerItems(Player $player, int $amount): void {
        $inventory = $player->getInventory();
        $contents = $inventory->getContents();
        $remaining = $amount;
        
        foreach ($contents as $slot => $item) {
            
            if ($item->equals($this->item, true, false)) {
                $itemCount = $item->getCount();
                if ($itemCount <= $remaining) {
                    $inventory->clear($slot);
                    $remaining -= $itemCount;
                } else {
                    $inventory->setItem($slot, $item->setCount($itemCount - $remaining));
                    $remaining = 0;
                }
                
                if ($remaining === 0) {
                    break;
                }
            }
        }
    }
}