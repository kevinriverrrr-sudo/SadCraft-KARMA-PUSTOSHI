<?php

declare(strict_types=1);

namespace sadcraft\npc;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\item\StringToItemParser;
use pocketmine\item\Item;
use pocketmine\utils\Config;
use pocketmine\world\Position;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\IntTag;

class Main extends PluginBase implements Listener {

    private Config $npcData;
    private array $npcSettings = [];
    private array $activeNpcs = [];
    private array $spawnedEntityIds = [];

    public function onEnable(): void {
        $this->saveResource("config.yml");
        $cfg = $this->getConfig();

        $this->npcData = new Config($this->getDataFolder() . "npcs.json", Config::JSON);
        $this->npcSettings = $cfg->get("npc_settings", []);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        if ($cfg->getNested("auto_spawn.enabled", true)) {
            $interval = $cfg->getNested("auto_spawn.check_interval", 60);
            $this->getScheduler()->scheduleRepeatingTask(new NPCSpawnTask($this), $interval * 20);
        }

        $this->getLogger()->info("§aSadNPC запущен! Тиры: " . count($this->npcSettings));
    }

    public function onDisable(): void {
        $this->npcData->save();
    }

    public function getNpcSettings(): array {
        return $this->npcSettings;
    }

    public function getActiveNpcCount(int $tier): int {
        $count = 0;
        foreach ($this->activeNpcs as $npc) {
            if (($npc["tier"] ?? 0) === $tier) {
                $count++;
            }
        }
        return $count;
    }

    public function spawnNPCAt(Position $pos, int $tier): bool {
        if (!isset($this->npcSettings[$tier])) {
            return false;
        }

        $settings = $this->npcSettings[$tier];
        $entityType = $settings["entity_type"] ?? "zombie";

        $world = $pos->getWorld();
        $location = \pocketmine\entity\Location::fromObject($pos, $world, 0, 0);
        $nbt = \pocketmine\nbt\tag\CompoundTag::create();

        $entity = null;
        switch ($entityType) {
            case "zombie":
                $entity = new \pocketmine\entity\Zombie($location, $nbt);
                break;
            case "skeleton":
                $entity = new \pocketmine\entity\Skeleton($location, $nbt);
                break;
            default:
                $entity = new \pocketmine\entity\Zombie($location, $nbt);
                break;
        }

        if ($entity === null) {
            return false;
        }

        $entity->setMaxHealth($settings["health"] ?? 20);
        $entity->setHealth($settings["health"] ?? 20);

        $nbt = $entity->saveNBT();
        $nbt->setTag("sadcraft_tier", new IntTag($tier));
        $nbt->setTag("sadcraft_npc", new StringTag("true"));
        $entity->saveNBT();

        $nameTag = $this->getTierColor($tier) . $settings["name"] . " §7[Тир {$tier}]";
        $entity->setNameTag($nameTag);
        $entity->setNameTagAlwaysVisible(true);

        $entity->spawnToAll();

        $this->activeNpcs[$entity->getId()] = [
            "tier" => $tier,
            "entity_id" => $entity->getId(),
            "x" => (int)$pos->getX(),
            "y" => (int)$pos->getY(),
            "z" => (int)$pos->getZ(),
            "spawned" => time(),
        ];

        $this->spawnedEntityIds[$entity->getId()] = $tier;

        return true;
    }

    public function onEntityDeath(EntityDeathEvent $event): void {
        $entity = $event->getEntity();
        $entityId = $entity->getId();

        if (!isset($this->spawnedEntityIds[$entityId])) {
            return;
        }

        $tier = $this->spawnedEntityIds[$entityId];
        $settings = $this->npcSettings[$tier] ?? null;
        if ($settings === null) {
            unset($this->spawnedEntityIds[$entityId]);
            unset($this->activeNpcs[$entityId]);
            return;
        }

        $killer = null;
        $cause = $entity->getLastDamageCause();
        if ($cause instanceof EntityDamageByEntityEvent) {
            $damager = $cause->getDamager();
            if ($damager instanceof Player) {
                $killer = $damager;
            }
        }

        $npcName = $settings["name"] ?? "NPC";
        $rewardScrap = $settings["reward_scrap"] ?? 0;
        $rewardKarma = $settings["reward_karma"] ?? 5;

        $lootItems = $this->generateLoot($settings);

        $droppedItems = [];
        foreach ($lootItems as $item) {
            $event->getDrops()[] = $item;
            $droppedItems[] = $item->getVanillaName() . " x" . $item->getCount();
        }

        if ($killer !== null) {
            $economy = $this->getServer()->getPluginManager()->getPlugin("SadEconomy");
            if ($economy !== null && $rewardScrap > 0) {
                $core = $this->getServer()->getPluginManager()->getPlugin("SadCore");
                $multiplier = 1.0;
                if ($core !== null) {
                    $multiplier = $core->getLootMultiplier($killer->getName());
                }
                $finalReward = (int)($rewardScrap * $multiplier);
                $economy->addBalance($killer->getName(), $finalReward);
                $killer->sendMessage("§aНаграда: §e{$finalReward}S §7" . ($multiplier > 1.0 ? "(x{$multiplier} карма!)" : ""));
            }

            $core = $this->getServer()->getPluginManager()->getPlugin("SadCore");
            if ($core !== null) {
                $core->addKarma($killer->getName(), $rewardKarma);
                $killer->sendMessage("§aКарма: +{$rewardKarma}");
            }

            $killer->sendMessage("§c§l[NPC] §r§aТы убил §f{$npcName}§a!");

            if (!empty($droppedItems)) {
                $killer->sendMessage("§7Лут: §f" . implode("§7, §f", array_slice($droppedItems, 0, 5)));
            }

            if ($tier >= 4) {
                foreach ($this->getServer()->getOnlinePlayers() as $online) {
                    if ($online->getId() !== $killer->getId()) {
                        $online->sendMessage("§c§l[NPC] §r§f" . $killer->getName() . " §7убил §f{$npcName} §7[Тир {$tier}]!");
                    }
                }
            }
        }

        unset($this->spawnedEntityIds[$entityId]);
        unset($this->activeNpcs[$entityId]);
    }

    private function generateLoot(array $settings): array {
        $lootTable = $settings["loot_table"] ?? [];
        $lootCount = $settings["loot_count"] ?? 3;
        $items = [];

        foreach ($lootTable as $entry) {
            $parts = explode(":", $entry);
            if (count($parts) < 4) continue;

            $itemName = $parts[0];
            $meta = (int)$parts[1];
            $count = (int)$parts[2];
            $chance = (float)$parts[3];

            if (mt_rand() / mt_getrandmax() <= $chance) {
                $item = $this->parseItem($itemName, $count);
                if ($item !== null) {
                    $items[] = $item;
                }
            }

            if (count($items) >= $lootCount) break;
        }

        if (empty($items) && !empty($lootTable)) {
            $firstEntry = explode(":", $lootTable[0]);
            if (count($firstEntry) >= 3) {
                $item = $this->parseItem($firstEntry[0], (int)$firstEntry[2]);
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    private function parseItem(string $itemName, int $count): ?Item {
        try {
            $parser = StringToItemParser::getInstance();
            $item = $parser->parse($itemName);
            if ($item === null) return null;
            $item->setCount($count);
            return $item;
        } catch (\Exception $e) {
            return null;
        }
    }

    private function getTierColor(int $tier): string {
        return match($tier) {
            1 => "§7",
            2 => "§e",
            3 => "§6",
            4 => "§c",
            5 => "§5§l",
            default => "§f",
        };
    }

    public function autoSpawn(): void {
        $core = $this->getServer()->getPluginManager()->getPlugin("SadCore");
        if ($core === null) return;

        $world = $this->getServer()->getWorldManager()->getDefaultWorld();
        if ($world === null) return;

        $cfg = $this->getConfig();
        $maxPerTier = $cfg->getNested("auto_spawn.max_per_tier", 5);
        $spawnRadius = $cfg->getNested("auto_spawn.spawn_radius", 50);

        $zonesConfig = $core->getZonesConfig();
        $zones = $zonesConfig->get("zones", []);

        foreach ($this->npcSettings as $tier => $settings) {
            $currentCount = $this->getActiveNpcCount($tier);
            if ($currentCount >= $maxPerTier) continue;

            $tierZones = array_filter($zones, fn($z) => ($z["tier"] ?? 0) === $tier);
            if (empty($tierZones)) continue;

            $toSpawn = $maxPerTier - $currentCount;
            for ($i = 0; $i < $toSpawn; $i++) {
                $zone = $tierZones[array_rand($tierZones)];

                $x = $zone["x"] + mt_rand(-$spawnRadius, $spawnRadius);
                $z = $zone["z"] + mt_rand(-$spawnRadius, $spawnRadius);

                try {
                    $y = $world->getHighestBlockAt($x, $z) + 1;
                } catch (\pocketmine\world\WorldException $e) {
                    continue;
                }

                if ($y < 1) continue;

                $pos = new Position($x, $y, $z, $world);
                $this->spawnNPCAt($pos, $tier);
            }
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        switch ($command->getName()) {
            case "npcspawn":
                if (!$sender instanceof Player) {
                    $sender->sendMessage("Только для игроков!");
                    return true;
                }
                if (empty($args)) {
                    $sender->sendMessage("§cИспользование: /npcspawn <тир 1-5>");
                    return true;
                }
                $tier = (int)$args[0];
                if ($tier < 1 || $tier > 5) {
                    $sender->sendMessage("§cТир от 1 до 5!");
                    return true;
                }
                $this->spawnNPCAt($sender->getPosition(), $tier);
                $sender->sendMessage("§aNPC тира {$tier} заспавнен!");
                return true;

            case "npckill":
                foreach ($this->activeNpcs as $npc) {
                    $entity = $sender->getServer()->getWorldManager()->getDefaultWorld()?->getEntity($npc["entity_id"]);
                    if ($entity !== null) {
                        $entity->kill();
                    }
                }
                $this->activeNpcs = [];
                $this->spawnedEntityIds = [];
                $sender->sendMessage("§aВсе NPC убиты!");
                return true;

            case "npclist":
                $sender->sendMessage("§c§l[NPC] §r§7Активные NPC:");
                foreach ($this->activeNpcs as $npc) {
                    $tier = $npc["tier"];
                    $settings = $this->npcSettings[$tier] ?? null;
                    $name = $settings["name"] ?? "Unknown";
                    $sender->sendMessage("§7- §e{$name} §7[Тир {$tier}] X:{$npc['x']} Z:{$npc['z']}");
                }
                if (empty($this->activeNpcs)) {
                    $sender->sendMessage("§7Нет активных NPC.");
                }
                return true;
        }
        return false;
    }
}
