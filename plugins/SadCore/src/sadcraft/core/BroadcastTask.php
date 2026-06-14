<?php

declare(strict_types=1);

namespace sadcraft\core;

use pocketmine\scheduler\Task;

class BroadcastTask extends Task {

    private Main $plugin;

    public function __construct(Main $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        $this->plugin->broadcastZones();
    }
}
