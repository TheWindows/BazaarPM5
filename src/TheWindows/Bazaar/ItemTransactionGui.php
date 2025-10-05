<?php
declare(strict_types=1);

namespace TheWindows\Bazaar;

use muqsit\invmenu\InvMenu;
use muqsit\invmenu\transaction\InvMenuTransaction;
use muqsit\invmenu\transaction\InvMenuTransactionResult;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\utils\DyeColor;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\player\Player;
use onebone\economyapi\EconomyAPI;
use jojoe77777\FormAPI\CustomForm;
use pocketmine\scheduler\ClosureTask;

class ItemTransactionGui {
    private InvMenu $menu;
    private \pocketmine\plugin\Plugin $plugin;
    private ?EconomyAPI $economy;
    private ?DatabaseManager $dbManager;
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

    public function __construct(\pocketmine\plugin\Plugin $plugin, ?EconomyAPI $economy, ?DatabaseManager $dbManager, Item $item, float $buyPrice, float $sellPrice, string $itemId) {
        $this->plugin = $plugin;
        $this->economy = $economy;
        $this->dbManager = $dbManager;
        $this->item = $item;
        $this->buyPrice = $buyPrice;
        $this->sellPrice = $sellPrice;
        $this->itemId = $itemId;
        
        $this->menu = InvMenu::create(InvMenu::TYPE_CHEST);
        $this->menu->setName($this->plugin->getConfig()->getNested("messages.item_transaction_gui_title", "§l§e» §r§bItem Transaction §l§e«"));
        
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
                        $shopGui = new ShopGui($this->plugin, $this->economy, $this->dbManager);
                        $shopGui->open($player);
                        break;
                }
            } catch (\Exception $e) {
                $player->sendMessage(str_replace("{error}", $e->getMessage(), $this->plugin->getConfig()->getNested("messages.transaction_error", "§cError processing transaction: {error}")));
            }
            
            return $transaction->discard();
        });
    }

    public function open(Player $player): void {
        if (!$this->economy instanceof EconomyAPI) {
            $player->sendMessage($this->plugin->getConfig()->getNested("messages.economy_unavailable", "§cEconomy system is not available!"));
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
            "§r",
            "§7Buy Amount: §a" . $buyAmount,
            "§7Sell Amount: §c" . $sellAmount
        ]);
        
        $inventory->setItem(13, $displayItem);
        
        $buy1 = StringToItemParser::getInstance()->parse("emerald")->setCustomName("§aBuy 1");
        $buy32 = StringToItemParser::getInstance()->parse("emerald_block")->setCustomName("§aBuy 32");
        $buy64 = StringToItemParser::getInstance()->parse("emerald_block")->setCustomName("§aBuy 64");
        $sell1 = StringToItemParser::getInstance()->parse("redstone")->setCustomName("§cSell 1");
        $sell32 = StringToItemParser::getInstance()->parse("redstone_block")->setCustomName("§cSell 32");
        $sell64 = StringToItemParser::getInstance()->parse("redstone_block")->setCustomName("§cSell 64");
        $customBuy = StringToItemParser::getInstance()->parse("paper")->setCustomName("§eCustom Buy Amount");
        $customSell = StringToItemParser::getInstance()->parse("paper")->setCustomName("§eCustom Sell Amount");
        $confirm = StringToItemParser::getInstance()->parse("diamond")->setCustomName("§bConfirm");
        $back = StringToItemParser::getInstance()->parse("barrier")->setCustomName("§cBack");
        
        $inventory->setItem(self::BUY_1_SLOT, $buy1);
        $inventory->setItem(self::BUY_32_SLOT, $buy32);
        $inventory->setItem(self::BUY_64_SLOT, $buy64);
        $inventory->setItem(self::SELL_1_SLOT, $sell1);
        $inventory->setItem(self::SELL_32_SLOT, $sell32);
        $inventory->setItem(self::SELL_64_SLOT, $sell64);
        $inventory->setItem(self::CUSTOM_BUY_SLOT, $customBuy);
        $inventory->setItem(self::CUSTOM_SELL_SLOT, $customSell);
        $inventory->setItem(self::CONFIRM_SLOT, $confirm);
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
            $player->sendMessage($this->plugin->getConfig()->getNested("messages.economy_unavailable", "§cEconomy system is not available!"));
            return;
        }

        $totalCost = $this->buyPrice * $amount;
        $money = $this->economy->myMoney($player);
        
        if ($money < $totalCost) {
            $player->sendMessage(str_replace("{total}", number_format($totalCost, 2), $this->plugin->getConfig()->getNested("messages.not_enough_money", "§cYou don't have enough money! Need: {total}")));
            return;
        }
        
        if (!$player->getInventory()->canAddItem($this->item->setCount($amount))) {
            $player->sendMessage($this->plugin->getConfig()->getNested("messages.not_enough_inventory_space", "§cNot enough inventory space!"));
            return;
        }
        
        $this->economy->reduceMoney($player, $totalCost);
        $this->dbManager->recordTransaction($this->itemId, $player->getName(), "buy", $amount, $totalCost);
        
        $item = clone $this->item;
        $item->setCount($amount);
        $player->getInventory()->addItem($item);
        
        $player->sendMessage(str_replace(
            ["{amount}", "{item}", "{total}"],
            [$amount, $this->item->getName(), number_format($totalCost, 2)],
            $this->plugin->getConfig()->getNested("messages.buy_success", "§aBought {amount}x {item} for {total}")
        ));
    }

    private function handleSell(Player $player, int $amount): void {
        if (!$this->economy instanceof EconomyAPI) {
            $player->sendMessage($this->plugin->getConfig()->getNested("messages.economy_unavailable", "§cEconomy system is not available!"));
            return;
        }

        $count = $this->countPlayerItems($player);
        
        if ($count < $amount) {
            $player->sendMessage(str_replace("{amount}", (string)$amount, $this->plugin->getConfig()->getNested("messages.not_enough_items", "§cYou don't have enough items! Need: {amount}x")));
            return;
        }
        
        $totalProfit = $this->sellPrice * $amount;
        $this->economy->addMoney($player, $totalProfit);
        $this->dbManager->recordTransaction($this->itemId, $player->getName(), "sell", $amount, $totalProfit);
        $this->removePlayerItems($player, $amount);
        
        $player->sendMessage(str_replace(
            ["{amount}", "{item}", "{total}"],
            [$amount, $this->item->getName(), number_format($totalProfit, 2)],
            $this->plugin->getConfig()->getNested("messages.sell_success", "§aSold {amount}x {item} for {total}")
        ));
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
                        $player->sendMessage($this->plugin->getConfig()->getNested("messages.invalid_amount", "§cInvalid amount!"));
                        $this->open($player);
                        return;
                    }

                    if ($isBuy) {
                        $totalCost = $this->buyPrice * $amount;
                        if ($money < $totalCost) {
                            $player->sendMessage(str_replace("{total}", number_format($totalCost, 2), $this->plugin->getConfig()->getNested("messages.not_enough_money", "§cYou don't have enough money! Need: {total}")));
                            $this->open($player);
                            return;
                        }
                        $this->setBuyAmount($player, $amount);
                    } else {
                        if ($itemCount < $amount) {
                            $player->sendMessage(str_replace("{amount}", (string)$amount, $this->plugin->getConfig()->getNested("messages.not_enough_items", "§cYou don't have enough items! Need: {amount}x")));
                            $this->open($player);
                            return;
                        }
                        $this->setSellAmount($player, $amount);
                    }

                    $this->initInventory($player);
                    $this->menu->send($player);
                });

                $form->setTitle($isBuy
                    ? $this->plugin->getConfig()->getNested("messages.buy_amount_form_title", "§e» §aBuy Amount §e«")
                    : $this->plugin->getConfig()->getNested("messages.sell_amount_form_title", "§e» §cSell Amount §e«"));
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