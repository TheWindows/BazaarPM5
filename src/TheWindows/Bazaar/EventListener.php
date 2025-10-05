<?php
declare(strict_types=1);

namespace TheWindows\Bazaar;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use onebone\economyapi\EconomyAPI;

class EventListener implements Listener {
    private EconomyAPI $economy;

    public function __construct(EconomyAPI $economy) {
        $this->economy = $economy;
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        if ($this->economy->myMoney($player) === null) {
            $this->economy->setMoney($player, 100);
        }
    }
}