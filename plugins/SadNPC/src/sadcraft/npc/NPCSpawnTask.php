<?php

declare(strict_types=1);

namespace sadcraft\npc;

use pocketmine\scheduler\Task;

class NPCSpawnTask extends Task {

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        $this->plugin->autoSpawn();
    }
}
