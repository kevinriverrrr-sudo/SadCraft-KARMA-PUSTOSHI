<?php

declare(strict_types=1);

namespace sadcraft\warps;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\Config;
use pocketmine\world\Position;

class Main extends PluginBase implements Listener {

    private Config $warpsData;
    private Config $homesData;
    private array $teleportQueue = [];
    private array $cooldowns = [];

    public function onEnable(): void {
        $this->saveResource("config.yml");

        $this->warpsData = new Config($this->getDataFolder() . "warps.json", Config::JSON);
        $this->homesData = new Config($this->getDataFolder() . "homes.json", Config::JSON);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("§aSadWarps запущен!");
    }

    public function onDisable(): void {
        $this->warpsData->save();
        $this->homesData->save();
    }

    public function onPlayerMove(PlayerMoveEvent $event): void {
        if (!$this->getConfig()->get("cancel_on_move", true)) {
            return;
        }

        $player = $event->getPlayer();
        $name = strtolower($player->getName());

        if (isset($this->teleportQueue[$name])) {
            $from = $event->getFrom();
            $to = $event->getTo();

            if ($from->getX() !== $to->getX() || $from->getZ() !== $to->getZ()) {
                unset($this->teleportQueue[$name]);
                $player->sendMessage("§cТелепортация отменена! Ты двинулся.");
            }
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage("Только для игроков!");
            return true;
        }

        switch ($command->getName()) {
            case "warp":
                return $this->handleWarp($sender, $args);
            case "warps":
                return $this->handleWarps($sender);
            case "setwarp":
                return $this->handleSetWarp($sender, $args);
            case "delwarp":
                return $this->handleDelWarp($sender, $args);
            case "home":
                return $this->handleHome($sender, $args);
            case "sethome":
                return $this->handleSetHome($sender, $args);
            case "delhome":
                return $this->handleDelHome($sender, $args);
            case "homes":
                return $this->handleHomes($sender);
        }
        return false;
    }

    private function handleWarp(Player $player, array $args): bool {
        if (empty($args)) {
            $player->sendMessage("§cИспользование: /warp <название>");
            return true;
        }

        $name = strtolower($args[0]);
        $warp = $this->warpsData->get($name);

        if ($warp === null) {
            $player->sendMessage("§cВарп §f{$name} §cне найден!");
            return true;
        }

        $this->teleportTo($player, $warp, "варп {$name}");
        return true;
    }

    private function handleWarps(Player $player): bool {
        $warps = $this->warpsData->getAll();
        if (empty($warps)) {
            $player->sendMessage("§7Нет доступных варпов.");
            return true;
        }

        $player->sendMessage("§c§l[ВАРПЫ] §r§7Доступные варпы:");
        foreach ($warps as $name => $data) {
            $player->sendMessage("§7- §e{$name} §7(X:{$data['x']} Z:{$data['z']})");
        }
        return true;
    }

    private function handleSetWarp(Player $player, array $args): bool {
        if (empty($args)) {
            $player->sendMessage("§cИспользование: /setwarp <название>");
            return true;
        }

        $name = strtolower($args[0]);
        $pos = $player->getPosition();

        $this->warpsData->set($name, [
            "x" => (int)$pos->getX(),
            "y" => (int)$pos->getY(),
            "z" => (int)$pos->getZ(),
            "world" => $pos->getWorld()->getFolderName(),
        ]);
        $this->warpsData->save();

        $player->sendMessage("§aВарп §e{$name} §aсоздан!");
        return true;
    }

    private function handleDelWarp(Player $player, array $args): bool {
        if (empty($args)) {
            $player->sendMessage("§cИспользование: /delwarp <название>");
            return true;
        }

        $name = strtolower($args[0]);
        if (!$this->warpsData->exists($name)) {
            $player->sendMessage("§cВарп §f{$name} §cне найден!");
            return true;
        }

        $this->warpsData->remove($name);
        $this->warpsData->save();
        $player->sendMessage("§aВарп §e{$name} §aудалён!");
        return true;
    }

    private function handleHome(Player $player, array $args): bool {
        $playerName = strtolower($player->getName());
        $homes = $this->homesData->get($playerName, []);

        if (empty($homes)) {
            $player->sendMessage("§cУ тебя нет домов! Используй /sethome");
            return true;
        }

        $homeName = !empty($args) ? strtolower($args[0]) : "home";

        if (!isset($homes[$homeName])) {
            $player->sendMessage("§cДом §f{$homeName} §cне найден!");
            $player->sendMessage("§7Твои дома: §e" . implode("§7, §e", array_keys($homes)));
            return true;
        }

        $this->teleportTo($player, $homes[$homeName], "дом {$homeName}");
        return true;
    }

    private function handleSetHome(Player $player, array $args): bool {
        $playerName = strtolower($player->getName());
        $homes = $this->homesData->get($playerName, []);
        $maxHomes = $this->getConfig()->get("max_homes", 3);

        $homeName = !empty($args) ? strtolower($args[0]) : "home";

        if (!isset($homes[$homeName]) && count($homes) >= $maxHomes) {
            $player->sendMessage("§cМаксимум домов: §e{$maxHomes}§c! Удали старый: /delhome");
            return true;
        }

        $pos = $player->getPosition();
        $homes[$homeName] = [
            "x" => (int)$pos->getX(),
            "y" => (int)$pos->getY(),
            "z" => (int)$pos->getZ(),
            "world" => $pos->getWorld()->getFolderName(),
        ];

        $this->homesData->set($playerName, $homes);
        $this->homesData->save();
        $player->sendMessage("§aДом §e{$homeName} §aустановлен!");
        return true;
    }

    private function handleDelHome(Player $player, array $args): bool {
        if (empty($args)) {
            $player->sendMessage("§cИспользование: /delhome <название>");
            return true;
        }

        $playerName = strtolower($player->getName());
        $homes = $this->homesData->get($playerName, []);
        $homeName = strtolower($args[0]);

        if (!isset($homes[$homeName])) {
            $player->sendMessage("§cДом §f{$homeName} §cне найден!");
            return true;
        }

        unset($homes[$homeName]);
        $this->homesData->set($playerName, $homes);
        $this->homesData->save();
        $player->sendMessage("§aДом §e{$homeName} §aудалён!");
        return true;
    }

    private function handleHomes(Player $player): bool {
        $playerName = strtolower($player->getName());
        $homes = $this->homesData->get($playerName, []);

        if (empty($homes)) {
            $player->sendMessage("§7У тебя нет домов. /sethome для создания.");
            return true;
        }

        $player->sendMessage("§c§l[ДОМА] §r§7Твои дома:");
        foreach ($homes as $name => $data) {
            $player->sendMessage("§7- §e{$name} §7(X:{$data['x']} Y:{$data['y']} Z:{$data['z']})");
        }
        return true;
    }

    private function teleportTo(Player $player, array $data, string $label): void {
        $name = strtolower($player->getName());
        $delay = $this->getConfig()->get("teleport_delay", 3);
        $cooldown = $this->getConfig()->get("teleport_cooldown", 30);

        if (isset($this->cooldowns[$name]) && time() < $this->cooldowns[$name]) {
            $remaining = $this->cooldowns[$name] - time();
            $player->sendMessage("§cПодожди §e{$remaining}с §cперед следующим телепортом!");
            return;
        }

        if ($delay > 0) {
            $player->sendMessage("§7Телепортация на §e{$label} §7через §e{$delay}с§7... Не двигайся!");
            $this->teleportQueue[$name] = [
                "data" => $data,
                "label" => $label,
                "time" => time() + $delay,
            ];

            $this->getScheduler()->scheduleDelayedTask(new TeleportTask($this, $player, $data, $label), $delay * 20);
        } else {
            $this->executeTeleport($player, $data, $label);
        }
    }

    public function executeTeleport(Player $player, array $data, string $label): void {
        $name = strtolower($player->getName());

        if (!isset($this->teleportQueue[$name]) && $this->getConfig()->get("teleport_delay", 3) > 0) {
            return;
        }

        unset($this->teleportQueue[$name]);

        $worldManager = $this->getServer()->getWorldManager();
        $worldName = $data["world"] ?? "world";

        if (!$worldManager->isWorldLoaded($worldName)) {
            $worldManager->loadWorld($worldName);
        }

        $world = $worldManager->getWorldByName($worldName);
        if ($world === null) {
            $player->sendMessage("§cМир не найден!");
            return;
        }

        $pos = new Position($data["x"], $data["y"], $data["z"], $world);
        $player->teleport($pos);
        $player->sendMessage("§aТелепортирован на §e{$label}§a!");

        $cooldown = $this->getConfig()->get("teleport_cooldown", 30);
        if ($cooldown > 0) {
            $this->cooldowns[$name] = time() + $cooldown;
        }
    }

    public function removeFromQueue(string $playerName): void {
        unset($this->teleportQueue[strtolower($playerName)]);
    }

    public function isInQueue(string $playerName): bool {
        return isset($this->teleportQueue[strtolower($playerName)]);
    }
}
