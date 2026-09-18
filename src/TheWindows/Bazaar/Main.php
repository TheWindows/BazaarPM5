<?php
declare(strict_types=1);

namespace TheWindows\Bazaar;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\TaskHandler;
use onebone\economyapi\EconomyAPI;
use muqsit\invmenu\InvMenuHandler;

class Main extends PluginBase {
    private ?ShopGui $shopGui = null;
    private ?DatabaseManager $dbManager = null;
    private ?TaskHandler $priceTaskHandler = null;

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->ensureConfigDefaults();

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
        if ($formAPI === null) {
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
        $this->getServer()->getCommandMap()->register("winshop", new class($this) extends Command {
            private Main $plugin;

            public function __construct(Main $plugin) {
                parent::__construct("bazaar", "Open the bazaar GUI", "/bazaar [updateprices|resetprices|reload|help]");
                $this->setPermission("winshop.command");
                $this->plugin = $plugin;
            }

            public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
                $config = $this->plugin->getConfig();

                if (count($args) === 0) {
                    if (!$sender instanceof Player) {
                        $sender->sendMessage((string) $config->getNested("messages.command_in_game_only", "§cThis command can only be used in-game!"));
                        return false;
                    }
                    if (!$this->testPermission($sender)) {
                        $sender->sendMessage((string) $config->getNested("messages.no_permission", "§cYou don't have permission to use this command!"));
                        return false;
                    }
                    $shop = $this->plugin->getShopGui();
                    if ($shop === null) {
                        $sender->sendMessage((string) $config->getNested("messages.economy_unavailable", "§cEconomy system is not available!"));
                        return false;
                    }
                    $shop->open($sender);
                    return true;
                }

                $sub = strtolower($args[0]);
                switch ($sub) {
                    case "updateprices":
                    case "update":
                        if (!$sender->hasPermission("winshop.admin")) {
                            $sender->sendMessage((string) $config->getNested("messages.no_permission", "§cYou don't have permission to use this command!"));
                            return false;
                        }
                        $count = $this->plugin->forcePriceUpdate();
                        $sender->sendMessage(str_replace(
                            "{count}",
                            (string) $count,
                            (string) $config->getNested("messages.prices_updated_admin", "§aFluctuated prices for {count} item(s).")
                        ));
                        return true;

                    case "resetprices":
                    case "reset":
                        if (!$sender->hasPermission("winshop.admin")) {
                            $sender->sendMessage((string) $config->getNested("messages.no_permission", "§cYou don't have permission to use this command!"));
                            return false;
                        }
                        $this->plugin->resetPricesToConfig();
                        $sender->sendMessage((string) $config->getNested("messages.prices_reset", "§aBazaar prices have been reset to config values."));
                        return true;

                    case "reload":
                        if (!$sender->hasPermission("winshop.admin")) {
                            $sender->sendMessage((string) $config->getNested("messages.no_permission", "§cYou don't have permission to use this command!"));
                            return false;
                        }
                        $this->plugin->reloadBazaar();
                        $sender->sendMessage((string) $config->getNested("messages.reloaded", "§aBazaar configuration reloaded."));
                        return true;

                    case "help":
                        $sender->sendMessage("§e» §bBazaar commands§e «");
                        $sender->sendMessage("§7/bazaar §8- §fOpen the bazaar");
                        if ($sender->hasPermission("winshop.admin")) {
                            $sender->sendMessage("§7/bazaar updateprices §8- §fForce a random price fluctuation");
                            $sender->sendMessage("§7/bazaar resetprices §8- §fReset prices to config values");
                            $sender->sendMessage("§7/bazaar reload §8- §fReload config and catalogue");
                        }
                        return true;

                    default:
                        $sender->sendMessage((string) $config->getNested("messages.unknown_subcommand", "§cUnknown subcommand! Use /bazaar help"));
                        return false;
                }
            }
        });

        $this->schedulePriceUpdates();

        $this->getServer()->getPluginManager()->registerEvents(new EventListener($economy), $this);
    }

    protected function onDisable(): void {
        if ($this->priceTaskHandler !== null) {
            try {
                $this->priceTaskHandler->cancel();
            } catch (\Throwable) {
                // Scheduler already gone.
            }
            $this->priceTaskHandler = null;
        }
        $this->dbManager?->close();
    }

    /**
     * Backfill new config keys for servers updating from older versions
     * without wiping their existing items/messages.
     */
    private function ensureConfigDefaults(): void {
        $config = $this->getConfig();
        $changed = false;

        $defaults = [
            "price-auto-update.enabled" => true,
            "price-auto-update.interval-minutes" => 30,
            "price-auto-update.max-fluctuation" => 0.1,
            "price-auto-update.announce" => true,
            "messages.prices_updated" => "§e§lBazaar §r§7» Prices have shifted! Check out the new deals with §e/bazaar§7.",
            "messages.prices_updated_admin" => "§aFluctuated prices for {count} item(s).",
            "messages.prices_reset" => "§aBazaar prices have been reset to config values.",
            "messages.reloaded" => "§aBazaar configuration reloaded.",
            "messages.unknown_subcommand" => "§cUnknown subcommand! Use /bazaar help"
        ];
        foreach ($defaults as $nested => $value) {
            if ($config->getNested($nested) === null) {
                $config->setNested($nested, $value);
                $changed = true;
            }
        }
        if ($config->get("custom-items") === null) {
            $config->set("custom-items", []);
            $changed = true;
        }
        if ($changed) {
            $config->save();
        }
    }

    public function schedulePriceUpdates(): void {
        if ($this->priceTaskHandler !== null) {
            try {
                $this->priceTaskHandler->cancel();
            } catch (\Throwable) {
                // Ignore.
            }
            $this->priceTaskHandler = null;
        }

        $config = $this->getConfig();
        if (!$config->getNested("price-auto-update.enabled", true)) {
            $this->getLogger()->info("Random price changes are disabled in config.");
            return;
        }

        $minutes = (float) $config->getNested("price-auto-update.interval-minutes", 30);
        if ($minutes <= 0) {
            $minutes = 30;
        }
        $periodTicks = max(20, (int) round($minutes * 60 * 20));
        $this->priceTaskHandler = $this->getScheduler()->scheduleRepeatingTask(new PriceUpdateTask($this), $periodTicks);
        $this->getLogger()->info("Random price changes scheduled every {$minutes} minute(s).");
    }

    /**
     * Force one random fluctuation round (admin command + task entry point).
     * @return int number of items whose price changed
     */
    public function forcePriceUpdate(): int {
        if ($this->dbManager === null) {
            return 0;
        }
        $changes = $this->dbManager->updatePrices($this->getConfig(), true);
        $this->shopGui?->refreshPrices();
        if (count($changes) > 0) {
            $this->getLogger()->info("Manual bazaar price fluctuation applied to " . count($changes) . " item(s).");
        }
        return count($changes);
    }

    public function resetPricesToConfig(): void {
        $this->dbManager?->resetPricesFromConfig();
        $this->shopGui?->refreshPrices();
    }

    public function reloadBazaar(): void {
        $this->reloadConfig();
        $this->ensureConfigDefaults();
        $this->dbManager?->syncPricesWithConfig();
        $this->shopGui?->reloadItems();
        $this->schedulePriceUpdates();
    }

    public function getShopGui(): ?ShopGui {
        return $this->shopGui;
    }

    public function getDatabaseManager(): ?DatabaseManager {
        return $this->dbManager;
    }
}
