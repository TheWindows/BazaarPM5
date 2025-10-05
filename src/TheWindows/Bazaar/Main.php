<?php
declare(strict_types=1);

namespace TheWindows\Bazaar;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use onebone\economyapi\EconomyAPI;
use muqsit\invmenu\InvMenuHandler;

class Main extends PluginBase {
    private ?ShopGui $shopGui = null;
    private ?DatabaseManager $dbManager = null;

    protected function onEnable(): void {
        
        $this->saveDefaultConfig();

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

        
        try {
            $this->dbManager = new DatabaseManager($this->getDataFolder() . "prices.db", $this);
        } catch (\Exception $e) {
            $this->getLogger()->error("Failed to initialize database: " . $e->getMessage());
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        $this->shopGui = new ShopGui($this, $economy, $this->dbManager);
        $this->getServer()->getCommandMap()->register("winshop", new class($this) extends \pocketmine\command\Command {
            private Main $plugin;

            public function __construct(Main $plugin) {
                parent::__construct("bazaar", "Open the bazaar GUI", "/bazaar");
                $this->setPermission("winshop.command");
                $this->plugin = $plugin;
            }

            public function execute(\pocketmine\command\CommandSender $sender, string $commandLabel, array $args): bool {
                $config = $this->plugin->getConfig();
                if (!$sender instanceof Player) {
                    $sender->sendMessage($config->getNested("messages.command_in_game_only", "§cThis command can only be used in-game!"));
                    return false;
                }
                
                if (!$this->testPermission($sender)) {
                    $sender->sendMessage($config->getNested("messages.no_permission", "§cYou don't have permission to use this command!"));
                    return false;
                }
                
                $this->plugin->getShopGui()->open($sender);
                return true;
            }
        });

        $this->getServer()->getPluginManager()->registerEvents(new EventListener($economy), $this);
    }

    public function getShopGui(): ?ShopGui {
        return $this->shopGui;
    }

    public function getDatabaseManager(): ?DatabaseManager {
        return $this->dbManager;
    }
}