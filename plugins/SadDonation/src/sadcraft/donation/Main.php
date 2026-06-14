<?php

declare(strict_types=1);

namespace sadcraft\donation;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\item\StringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {

    private Config $cooldownsData;
    private Config $ranksData;
    private array $kits = [];
    private array $ranks = [];

    public function onEnable(): void {
        $this->saveResource("config.yml");
        $cfg = $this->getConfig();

        $this->cooldownsData = new Config($this->getDataFolder() . "cooldowns.json", Config::JSON);
        $this->ranksData = new Config($this->getDataFolder() . "ranks.json", Config::JSON);

        $this->kits = $cfg->get("kits", []);
        $this->ranks = $cfg->get("ranks", []);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("§aSadDonation запущен! Китов: " . count($this->kits));
    }

    public function onDisable(): void {
        $this->cooldownsData->save();
        $this->ranksData->save();
    }

    public function getPlayerRank(string $playerName): string {
        return $this->ranksData->get(strtolower($playerName), "none");
    }

    public function setPlayerRank(string $playerName, string $rank): void {
        $this->ranksData->set(strtolower($playerName), $rank);
        $this->ranksData->save();

        $player = $this->getServer()->getPlayerByPrefix($playerName);
        if ($player !== null) {
            $rankData = $this->ranks[$rank] ?? null;
            if ($rankData !== null) {
                foreach ($rankData["permissions"] ?? [] as $perm) {
                    $this->getServer()->addOp($player->getName());
                }
            }
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage("Только для игроков!");
            return true;
        }

        switch ($command->getName()) {
            case "kit":
                return $this->handleKit($sender, $args);
            case "kits":
                return $this->handleKits($sender);
            case "donate":
                return $this->handleDonate($sender, $args);
            case "donshop":
                return $this->handleDonShop($sender);
        }
        return false;
    }

    private function handleKit(Player $player, array $args): bool {
        if (empty($args)) {
            $player->sendMessage("§cИспользование: /kit <название>");
            $player->sendMessage("§7Доступные киты: §e/kits");
            return true;
        }

        $kitName = strtolower($args[0]);
        if (!isset($this->kits[$kitName])) {
            $player->sendMessage("§cКит §f{$kitName} §cне найден!");
            return true;
        }

        $kit = $this->kits[$kitName];
        $permission = $kit["permission"] ?? "";

        if (!empty($permission) && !$player->hasPermission($permission)) {
            $player->sendMessage("§cУ тебя нет доступа к этому киту!");
            $player->sendMessage("§7Купи ранг в донат-магазине: §e/donshop");
            return true;
        }

        $playerName = strtolower($player->getName());
        $cooldown = $kit["cooldown"] ?? 86400;
        $lastUsed = $this->cooldownsData->get("{$playerName}.{$kitName}", 0);
        $now = time();

        if ($now - $lastUsed < $cooldown) {
            $remaining = $cooldown - ($now - $lastUsed);
            $hours = (int)($remaining / 3600);
            $minutes = (int)(($remaining % 3600) / 60);
            $player->sendMessage("§cКит на перезарядке! Осталось: §e{$hours}ч {$minutes}м");
            return true;
        }

        $items = $kit["items"] ?? [];
        $given = 0;
        $inventory = $player->getInventory();

        foreach ($items as $itemStr) {
            $item = $this->parseItem($itemStr);
            if ($item !== null) {
                $remainingSpace = $inventory->getAddableItemQuantity($item);
                if ($remainingSpace > 0) {
                    $item->setCount(min($item->getCount(), $remainingSpace));
                    $inventory->addItem($item);
                    $given++;
                }
            }
        }

        if ($given > 0) {
            $this->cooldownsData->set("{$playerName}.{$kitName}", $now);
            $this->cooldownsData->save();
            $kitDisplayName = $kit["name"] ?? $kitName;
            $player->sendMessage("§aКит §e{$kitDisplayName} §aполучен! ({$given} предметов)");
        } else {
            $player->sendMessage("§cИнвентарь полон! Освободи место.");
        }

        return true;
    }

    private function handleKits(Player $player): bool {
        $player->sendMessage("§c§l[КИТЫ] §r§7Доступные наборы:");

        foreach ($this->kits as $name => $kit) {
            $permission = $kit["permission"] ?? "";
            $hasAccess = empty($permission) || $player->hasPermission($permission);
            $displayName = $kit["name"] ?? $name;
            $desc = $kit["description"] ?? "";

            if ($hasAccess) {
                $playerName = strtolower($player->getName());
                $cooldown = $kit["cooldown"] ?? 86400;
                $lastUsed = $this->cooldownsData->get("{$playerName}.{$name}", 0);
                $now = time();
                $ready = ($now - $lastUsed) >= $cooldown;

                $status = $ready ? "§aГотов" : "§cПерезарядка";
                $player->sendMessage("§7- §e{$displayName} §7- {$status} §7| §8{$desc}");
            } else {
                $player->sendMessage("§7- §8{$displayName} §7(§c locked§7) §8{$desc}");
            }
        }
        return true;
    }

    private function handleDonate(Player $player, array $args): bool {
        if (empty($args)) {
            $currentRank = $this->getPlayerRank($player->getName());
            $player->sendMessage("§c§l[ДОНАТ] §r§7Твой ранг: §e" . ($currentRank === "none" ? "Нет" : $currentRank));
            $player->sendMessage("§7Донат-магазин: §e/donshop");
            return true;
        }

        $rankName = strtolower($args[0]);
        if (!isset($this->ranks[$rankName])) {
            $player->sendMessage("§cРанг не найден! Доступные: §e/donshop");
            return true;
        }

        $rank = $this->ranks[$rankName];
        $price = $rank["price"] ?? 0;

        $economy = $this->getServer()->getPluginManager()->getPlugin("SadEconomy");
        if ($economy === null) {
            $player->sendMessage("§cЭкономика не загружена!");
            return true;
        }

        $balance = $economy->getBalance($player->getName());
        if ($balance < $price) {
            $player->sendMessage("§cНедостаточно средств! Нужно: §e{$price}S§c, у тебя: §e{$balance}S");
            return true;
        }

        $economy->subtractBalance($player->getName(), $price);
        $this->setPlayerRank($player->getName(), $rankName);

        $displayName = $rank["name"] ?? $rankName;
        $player->sendMessage("§a§lРанг {$displayName} куплен! §aСумма: §e{$price}S");

        foreach ($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
            $onlinePlayer->sendMessage("§c§l[ДОНАТ] §r§f" . $player->getName() . " §7купил ранг §e{$displayName}§7!");
        }

        return true;
    }

    private function handleDonShop(Player $player): bool {
        $player->sendMessage("§c§l[ДОНАТ-МАГАЗИН] §r§7Ранги:");

        foreach ($this->ranks as $name => $rank) {
            $displayName = $rank["name"] ?? $name;
            $price = $rank["price"] ?? 0;
            $desc = $rank["description"] ?? "";
            $player->sendMessage("§e{$displayName} §7— §e{$price}S §7| §8{$desc}");
            $player->sendMessage("§7  Купить: §e/donate {$name}");
        }
        return true;
    }

    private function parseItem(string $itemStr): ?\pocketmine\item\Item {
        $parts = explode(":", $itemStr);
        if (count($parts) < 3) return null;

        $itemName = $parts[0];
        $meta = (int)$parts[1];
        $count = (int)$parts[2];

        try {
            $parser = StringToItemParser::getInstance();
            $item = $parser->parse($itemName);
            if ($item === null) {
                return null;
            }
            $item->setCount($count);
            return $item;
        } catch (\Exception $e) {
            return null;
        }
    }
}
