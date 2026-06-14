<?php

declare(strict_types=1);

namespace sadcraft\warps;

use pocketmine\scheduler\Task;
use pocketmine\player\Player;

class TeleportTask extends Task {

    private Main $plugin;
    private string $playerName;
    private array $data;
    private string $label;

    public function __construct(Main $plugin, Player $player, array $data, string $label) {
        $this->plugin = $plugin;
        $this->playerName = strtolower($player->getName());
        $this->data = $data;
        $this->label = $label;
    }

    public function onRun(): void {
        $player = $this->plugin->getServer()->getPlayerByPrefix($this->playerName);
        if ($player === null || !$this->plugin->isInQueue($this->playerName)) {
            $this->plugin->removeFromQueue($this->playerName);
            return;
        }

        $this->plugin->executeTeleport($player, $this->data, $this->label);
    }
}
