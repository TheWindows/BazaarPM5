<?php
declare(strict_types=1);

namespace TheWindows\Bazaar;

use pocketmine\scheduler\Task;

class PriceUpdateTask extends Task {
    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        $plugin = $this->plugin;
        if (!$plugin->isEnabled()) {
            return;
        }

        $db = $plugin->getDatabaseManager();
        $shop = $plugin->getShopGui();
        if ($db === null) {
            return;
        }

        $changes = $db->updatePrices($plugin->getConfig());
        if (count($changes) === 0) {
            return;
        }

        $shop?->refreshPrices();

        $plugin->getLogger()->info("Bazaar prices fluctuated for " . count($changes) . " item(s).");

        $config = $plugin->getConfig();
        if ($config->getNested("price-auto-update.announce", true)) {
            $message = (string) $config->getNested("messages.prices_updated", "§e§lBazaar §r§7» Prices have shifted! Check out the new deals with §e/bazaar§7.");
            $plugin->getServer()->broadcastMessage($message);
        }
    }
}
