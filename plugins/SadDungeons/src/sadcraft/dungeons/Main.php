<?php

declare(strict_types=1);

namespace sadcraft\dungeons;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;
use pocketmine\world\Position;
use pocketmine\item\StringToItemParser;
use pocketmine\item\Item;
use pocketmine\entity\Entity;
use pocketmine\entity\Living;
use pocketmine\scheduler\ClosureTask;

class Main extends PluginBase implements Listener
{
    /** @var array<string, array> Active dungeon sessions: lowercase player name => dungeon data */
    private array $activeDungeons = [];

    /** @var array<string, int> Cooldown timestamps: lowercase player name => last dungeon end time */
    private array $cooldowns = [];

    /** @var array<int, string> Dungeon NPC entity IDs: entity ID => lowercase player name */
    private array $dungeonNpcs = [];

    /** @var array<int, string> Boss entity IDs: entity ID => lowercase player name */
    private array $bossEntities = [];

    /** @var int Incrementing slot counter for dungeon positioning */
    private int $dungeonSlot = 0;

    /** @var bool Flag set while spawning a boss NPC */
    private bool $spawningBoss = false;

    /** @var string Lowercase player name we are currently spawning NPCs for */
    private string $spawningFor = "";

    private const DUNGEON_BASE_X = 100000;
    private const DUNGEON_SPACING = 200;
    private const DUNGEON_Y = 64;

    // ─── Lifecycle ─────────────────────────────────────────────────────

    public function onEnable(): void
    {
        $this->saveDefaultConfig();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        // Timeout checker — every 30 seconds (600 ticks)
        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(function (): void {
                $this->checkTimeouts();
            }),
            600
        );

        $this->getLogger()->info("§aSadDungeons загружен! Тиры: 1–5");
    }

    public function onDisable(): void
    {
        // Return all active dungeon players on shutdown
        foreach (array_keys($this->activeDungeons) as $playerName) {
            $player = $this->getServer()->getPlayerByPrefix($playerName);
            if ($player !== null) {
                $this->failDungeon($player, "Сервер перезагружается");
            } else {
                $this->cleanupDungeon($playerName);
            }
        }
    }

    // ─── Commands ──────────────────────────────────────────────────────

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "Команда только для игроков!");
            return true;
        }

        return match ($command->getName()) {
            "dungeon"      => $this->handleDungeonCommand($sender, $args),
            "dungeonleave" => $this->handleDungeonLeaveCommand($sender),
            "dungeoninfo"  => $this->handleDungeonInfoCommand($sender),
            default        => false,
        };
    }

    private function handleDungeonCommand(Player $player, array $args): bool
    {
        $playerName = strtolower($player->getName());

        // Already in a dungeon?
        if (isset($this->activeDungeons[$playerName])) {
            $player->sendMessage(TF::RED . "Вы уже находитесь в подземелье!");
            return true;
        }

        // Validate tier argument
        if (count($args) < 1) {
            $player->sendMessage(TF::RED . "Использование: /dungeon <tier 1-5>");
            return true;
        }

        $tier = (int) $args[0];
        if ($tier < 1 || $tier > 5) {
            $player->sendMessage(TF::RED . "Тир подземелья должен быть от 1 до 5!");
            return true;
        }

        // Check cooldown
        $settings   = $this->getConfig()->get("settings", []);
        $cooldownSec = (int) ($settings["cooldown"] ?? 300);

        if (isset($this->cooldowns[$playerName])) {
            $remaining = $this->cooldowns[$playerName] + $cooldownSec - time();
            if ($remaining > 0) {
                $min = (int) ($remaining / 60);
                $sec = $remaining % 60;
                $player->sendMessage(TF::RED . "Подземелье будет доступно через {$min}м {$sec}с.");
                return true;
            }
            unset($this->cooldowns[$playerName]);
        }

        // Get dungeon configuration
        $dungeons = $this->getConfig()->get("dungeons", []);
        if (!isset($dungeons[$tier])) {
            $player->sendMessage(TF::RED . "Подземелье тира {$tier} не найдено!");
            return true;
        }

        $dungeonConf = $dungeons[$tier];
        $entryCost   = (int) ($dungeonConf["entry_cost"] ?? 0);

        // Check and deduct entry cost via SadEconomy
        if ($entryCost > 0) {
            $sadEconomy = $this->getServer()->getPluginManager()->getPlugin("SadEconomy");
            if ($sadEconomy === null || !$sadEconomy->isEnabled()) {
                $player->sendMessage(TF::RED . "Экономика недоступна! Обратитесь к администрации.");
                return true;
            }

            $balance = $sadEconomy->getBalance($player->getName());
            if ($balance < $entryCost) {
                $symbol = method_exists($sadEconomy, "getCurrencySymbol")
                    ? $sadEconomy->getCurrencySymbol()
                    : "S";
                $player->sendMessage(
                    TF::RED . "Недостаточно монет! Нужно: {$entryCost}{$symbol}, у вас: {$balance}{$symbol}"
                );
                return true;
            }

            if (!$sadEconomy->subtractBalance($player->getName(), $entryCost)) {
                $player->sendMessage(TF::RED . "Ошибка списания монет!");
                return true;
            }

            $player->sendMessage(TF::YELLOW . "Списано {$entryCost} монет за вход в подземелье.");
        }

        $this->startDungeon($player, $tier, $dungeonConf);
        return true;
    }

    private function handleDungeonLeaveCommand(Player $player): bool
    {
        $playerName = strtolower($player->getName());

        if (!isset($this->activeDungeons[$playerName])) {
            $player->sendMessage(TF::RED . "Вы не находитесь в подземелье!");
            return true;
        }

        $this->failDungeon($player, "Вы покинули подземелье");
        return true;
    }

    private function handleDungeonInfoCommand(Player $player): bool
    {
        $dungeons = $this->getConfig()->get("dungeons", []);
        $settings = $this->getConfig()->get("settings", []);

        $player->sendMessage(TF::GOLD . "═══════ Подземелья SadCraft ═══════");

        foreach ($dungeons as $tier => $d) {
            $name  = $d["name"] ?? "Неизвестно";
            $rooms = $d["rooms"] ?? 0;
            $npcs  = $d["npc_count"] ?? 0;
            $cost  = $d["entry_cost"] ?? 0;
            $player->sendMessage(TF::YELLOW . "Тир {$tier}: " . TF::WHITE . $name);
            $player->sendMessage(TF::GRAY . "  Комнат: {$rooms} | NPC: {$npcs} | Вход: {$cost} монет");
        }

        $cooldown    = $settings["cooldown"] ?? 300;
        $timeout     = $settings["timeout"] ?? 1800;
        $roomTimeout = $settings["room_timeout"] ?? 120;
        $player->sendMessage(
            TF::AQUA . "Кулдаун: {$cooldown}с | Таймаут: {$timeout}с | На комнату: {$roomTimeout}с"
        );

        $playerName = strtolower($player->getName());
        if (isset($this->cooldowns[$playerName])) {
            $remaining = $this->cooldowns[$playerName] + (int) ($settings["cooldown"] ?? 300) - time();
            if ($remaining > 0) {
                $player->sendMessage(TF::RED . "Ваш кулдаун: {$remaining}с");
            }
        }

        return true;
    }

    // ─── Dungeon Lifecycle ─────────────────────────────────────────────

    private function startDungeon(Player $player, int $tier, array $dungeonConf): void
    {
        $playerName   = strtolower($player->getName());
        $originalPos  = $player->getPosition();

        // Calculate unique dungeon position far from normal gameplay
        $slot     = $this->dungeonSlot++;
        $dungeonX = self::DUNGEON_BASE_X + ($slot * self::DUNGEON_SPACING);
        $dungeonZ = ($slot % 256) * self::DUNGEON_SPACING;

        $totalRooms = (int) ($dungeonConf["rooms"] ?? 5);
        $npcCount   = (int) ($dungeonConf["npc_count"] ?? 8);
        $npcTier    = (int) ($dungeonConf["npc_tier"] ?? $tier);

        $this->activeDungeons[$playerName] = [
            "tier"              => $tier,
            "current_room"      => 1,
            "total_rooms"       => $totalRooms,
            "npc_count"         => $npcCount,
            "npc_tier"          => $npcTier,
            "npc_remaining"     => 0,
            "loot_table"        => $dungeonConf["loot_table"] ?? [],
            "boss_loot"         => $dungeonConf["boss_loot"] ?? [],
            "original_position" => $originalPos,
            "start_time"        => time(),
            "room_start_time"   => time(),
            "dungeon_x"         => $dungeonX,
            "dungeon_z"         => $dungeonZ,
            "dungeon_name"      => $dungeonConf["name"] ?? "Подземелье",
            "world_name"        => $originalPos->getWorld()->getFolderName(),
            "spawned_entities"  => [],  // entity_id => true (associative for O(1) unset)
        ];

        // Teleport to dungeon area
        $world      = $originalPos->getWorld();
        $dungeonPos = new Position($dungeonX, self::DUNGEON_Y, $dungeonZ, $world);
        $player->teleport($dungeonPos);

        // Build a small stone platform so the player doesn't fall through terrain
        $this->buildPlatform($world, $dungeonX, $dungeonZ);

        // Announce dungeon entry
        $dungeonName = $dungeonConf["name"] ?? "Подземелье";
        $player->sendMessage(TF::GOLD . "══════════════════════════════════");
        $player->sendMessage(TF::RED . "  ⚔ {$dungeonName} ⚔");
        $player->sendMessage(TF::GOLD . "══════════════════════════════════");
        $player->sendMessage(TF::YELLOW . "Тир: {$tier} | Комнат: {$totalRooms} | NPC: {$npcCount}");
        $player->sendMessage(TF::GRAY . "Убейте всех NPC для продвижения вперёд!");
        $player->sendMessage(TF::GRAY . "/dungeonleave — покинуть подземелье");

        // Broadcast to server
        $settings = $this->getConfig()->get("settings", []);
        $maxParty = (int) ($settings["max_party"] ?? 4);
        foreach ($this->getServer()->getOnlinePlayers() as $online) {
            if (strtolower($online->getName()) !== $playerName) {
                $online->sendMessage(
                    TF::DARK_RED . "[Подземелье] " . TF::WHITE . $player->getName()
                    . TF::GRAY . " вошёл в «{$dungeonName}» (Тир {$tier})"
                );
            }
        }

        // Spawn first room NPCs
        $this->spawnRoomNpcs($player);
    }

    private function buildPlatform(\pocketmine\world\World $world, int $cx, int $cz): void
    {
        $stone = \pocketmine\block\VanillaBlocks::STONE();
        $air   = \pocketmine\block\VanillaBlocks::AIR();
        $y     = self::DUNGEON_Y - 1;

        // Stone floor (15×15)
        for ($x = -7; $x <= 7; $x++) {
            for ($z = -7; $z <= 7; $z++) {
                $world->setBlockAt($cx + $x, $y, $cz + $z, $stone);
            }
        }

        // Clear air above the platform (15×15×4)
        for ($x = -7; $x <= 7; $x++) {
            for ($z = -7; $z <= 7; $z++) {
                for ($dy = 0; $dy <= 4; $dy++) {
                    $world->setBlockAt($cx + $x, self::DUNGEON_Y + $dy, $cz + $z, $air);
                }
            }
        }
    }

    private function spawnRoomNpcs(Player $player): void
    {
        $playerName = strtolower($player->getName());
        if (!isset($this->activeDungeons[$playerName])) {
            return;
        }

        $data = &$this->activeDungeons[$playerName];

        $totalNpcs   = $data["npc_count"];
        $totalRooms  = $data["total_rooms"];
        $currentRoom = $data["current_room"];
        $npcTier     = $data["npc_tier"];

        // Distribute NPCs evenly across rooms (extra NPCs go to earlier rooms)
        $roomNpcs = $this->getNpcsPerRoom($totalNpcs, $totalRooms, $currentRoom);
        $data["npc_remaining"]   = $roomNpcs;
        $data["room_start_time"] = time();

        // Spawn NPCs in a circle around the dungeon centre
        $sadNpc = $this->getServer()->getPluginManager()->getPlugin("SadNPC");
        if ($sadNpc === null || !$sadNpc->isEnabled()) {
            $player->sendMessage(TF::RED . "Ошибка: SadNPC недоступен! Используйте /dungeonleave");
            return;
        }

        $world = $player->getWorld();
        $this->spawningFor = $playerName;
        $this->spawningBoss = false;

        for ($i = 0; $i < $roomNpcs; $i++) {
            $angle  = (2.0 * M_PI * $i) / max($roomNpcs, 1);
            $radius = 8 + mt_rand(0, 5);
            $npcX   = (int) ($data["dungeon_x"] + $radius * cos($angle));
            $npcZ   = (int) ($data["dungeon_z"] + $radius * sin($angle));
            $npcPos = new Position($npcX, self::DUNGEON_Y, $npcZ, $world);

            $sadNpc->spawnNPCAt($npcPos, $npcTier);
        }

        $this->spawningFor  = "";
        $this->spawningBoss = false;

        $player->sendMessage(
            TF::AQUA . "⚔ Комната {$currentRoom}/{$totalRooms} — Убейте NPC: {$roomNpcs}"
        );
    }

    private function spawnBoss(Player $player): void
    {
        $playerName = strtolower($player->getName());
        if (!isset($this->activeDungeons[$playerName])) {
            return;
        }

        $data = &$this->activeDungeons[$playerName];
        $tier     = $data["tier"];
        $bossTier = min($tier + 1, 5);

        $sadNpc = $this->getServer()->getPluginManager()->getPlugin("SadNPC");
        if ($sadNpc === null || !$sadNpc->isEnabled()) {
            $player->sendMessage(TF::RED . "Ошибка: SadNPC недоступен! Подземелье завершено.");
            $this->completeDungeon($player);
            return;
        }

        $world  = $player->getWorld();
        $bossX  = $data["dungeon_x"] + 5;
        $bossZ  = $data["dungeon_z"] + 5;
        $bossPos = new Position($bossX, self::DUNGEON_Y, $bossZ, $world);

        // Set spawning flags so onEntitySpawn can identify the boss
        $this->spawningFor = $playerName;
        $this->spawningBoss = true;

        $sadNpc->spawnNPCAt($bossPos, $bossTier);

        $this->spawningBoss = false;
        $this->spawningFor  = "";

        $data["npc_remaining"] = 1; // Boss counts as 1 entity to kill

        $player->sendMessage(TF::DARK_RED . "══════════════════════════════════");
        $player->sendMessage(TF::RED . "  💀 БОС ПОЯВИЛСЯ! 💀");
        $player->sendMessage(TF::DARK_RED . "══════════════════════════════════");

        // Broadcast boss spawn
        foreach ($this->getServer()->getOnlinePlayers() as $online) {
            if (strtolower($online->getName()) !== $playerName) {
                $dungeonName = $data["dungeon_name"];
                $online->sendMessage(
                    TF::DARK_RED . "[Подземелье] " . TF::WHITE . $player->getName()
                    . TF::GRAY . " сражается с боссом в «{$dungeonName}»!"
                );
            }
        }
    }

    // ─── Kill Handling ─────────────────────────────────────────────────

    private function handleNpcKill(Player $player): void
    {
        $playerName = strtolower($player->getName());
        if (!isset($this->activeDungeons[$playerName])) {
            return;
        }

        $data = &$this->activeDungeons[$playerName];
        $data["npc_remaining"]--;

        $remaining    = $data["npc_remaining"];
        $currentRoom  = $data["current_room"];
        $totalRooms   = $data["total_rooms"];

        if ($remaining > 0) {
            $player->sendMessage(TF::YELLOW . "NPC убит! Осталось: {$remaining}");
            return;
        }

        // Room cleared — give room loot
        $this->giveLoot($player, $data["loot_table"]);

        if ($currentRoom < $totalRooms) {
            $player->sendMessage(TF::GREEN . "✔ Комната {$currentRoom} пройдена!");
            $this->advanceRoom($player);
        } else {
            // All rooms done — spawn boss
            $player->sendMessage(TF::GREEN . "✔ Все комнаты пройдены! Готовьтесь к бою с боссом!");
            // Small delay before boss spawns
            $this->getScheduler()->scheduleDelayedTask(
                new ClosureTask(function () use ($player, $playerName): void {
                    if (isset($this->activeDungeons[$playerName]) && $player->isConnected()) {
                        $this->spawnBoss($player);
                    }
                }),
                60 // 3 seconds
            );
        }
    }

    private function handleBossKill(Player $player): void
    {
        $playerName = strtolower($player->getName());
        if (!isset($this->activeDungeons[$playerName])) {
            return;
        }

        $data       = $this->activeDungeons[$playerName];
        $tier       = $data["tier"];
        $dungeonName = $data["dungeon_name"];

        // Give boss loot with SadCore loot multiplier
        $this->giveLoot($player, $data["boss_loot"]);

        // Karma reward via SadCore
        $sadCore = $this->getServer()->getPluginManager()->getPlugin("SadCore");
        if ($sadCore !== null && $sadCore->isEnabled()) {
            $karmaReward = $tier * 10;
            $sadCore->addKarma($player->getName(), $karmaReward);
            $player->sendMessage(TF::GREEN . "Карма: +{$karmaReward}");
        }

        // Economy bonus
        $sadEconomy = $this->getServer()->getPluginManager()->getPlugin("SadEconomy");
        if ($sadEconomy !== null && $sadEconomy->isEnabled()) {
            $bonus = $tier * 50;
            $sadEconomy->addBalance($player->getName(), $bonus);
            $symbol = method_exists($sadEconomy, "getCurrencySymbol")
                ? $sadEconomy->getCurrencySymbol()
                : "S";
            $player->sendMessage(TF::GREEN . "Бонус: +{$bonus}{$symbol}");
        }

        $player->sendMessage(TF::GOLD . "══════════════════════════════════");
        $player->sendMessage(TF::GREEN . "  🏆 БОС ПОВЕРЖЕН! 🏆");
        $player->sendMessage(TF::GOLD . "══════════════════════════════════");
        $player->sendMessage(TF::YELLOW . "Подземелье «{$dungeonName}» пройдено!");

        // Broadcast victory
        foreach ($this->getServer()->getOnlinePlayers() as $online) {
            if (strtolower($online->getName()) !== $playerName) {
                $online->sendMessage(
                    TF::GOLD . "[Подземелье] " . TF::WHITE . $player->getName()
                    . TF::GREEN . " прошёл «{$dungeonName}» (Тир {$tier})!"
                );
            }
        }

        $this->completeDungeon($player);
    }

    // ─── Room Advancement ──────────────────────────────────────────────

    private function advanceRoom(Player $player): void
    {
        $playerName = strtolower($player->getName());
        if (!isset($this->activeDungeons[$playerName])) {
            return;
        }

        $this->activeDungeons[$playerName]["current_room"]++;

        // Brief delay before spawning next room
        $this->getScheduler()->scheduleDelayedTask(
            new ClosureTask(function () use ($player, $playerName): void {
                if (isset($this->activeDungeons[$playerName]) && $player->isConnected()) {
                    $this->spawnRoomNpcs($player);
                }
            }),
            60 // 3 seconds
        );
    }

    // ─── Dungeon Completion / Failure ──────────────────────────────────

    private function completeDungeon(Player $player): void
    {
        $playerName = strtolower($player->getName());

        // Set cooldown
        $settings    = $this->getConfig()->get("settings", []);
        $cooldownSec = (int) ($settings["cooldown"] ?? 300);
        $this->cooldowns[$playerName] = time();

        $this->teleportBack($player);
        $this->cleanupDungeon($playerName);
    }

    private function failDungeon(Player $player, string $reason): void
    {
        $playerName = strtolower($player->getName());

        $player->sendMessage(TF::RED . "══════════════════════════════════");
        $player->sendMessage(TF::RED . "  ✖ {$reason}");
        $player->sendMessage(TF::RED . "══════════════════════════════════");

        // Set cooldown even on failure
        $settings    = $this->getConfig()->get("settings", []);
        $cooldownSec = (int) ($settings["cooldown"] ?? 300);
        $this->cooldowns[$playerName] = time();

        $this->teleportBack($player);
        $this->cleanupDungeon($playerName);
    }

    private function teleportBack(Player $player): void
    {
        $playerName = strtolower($player->getName());
        if (!isset($this->activeDungeons[$playerName])) {
            return;
        }

        $originalPos = $this->activeDungeons[$playerName]["original_position"];
        $worldName   = $this->activeDungeons[$playerName]["world_name"] ?? "";

        // Ensure the original world is loaded
        $world = $this->getServer()->getWorldManager()->getWorldByName($worldName);
        if ($world !== null && $originalPos instanceof Position) {
            $safePos = new Position(
                $originalPos->getX(),
                $originalPos->getY(),
                $originalPos->getZ(),
                $world
            );
            $player->teleport($safePos);
        } else {
            // Fallback to default world spawn
            $defaultWorld = $this->getServer()->getWorldManager()->getDefaultWorld();
            if ($defaultWorld !== null) {
                $player->teleport($defaultWorld->getSafeSpawn());
            }
        }

        $player->sendMessage(TF::GRAY . "Вы вернулись из подземелья.");
    }

    // ─── Cleanup ───────────────────────────────────────────────────────

    private function cleanupDungeon(string $playerName): void
    {
        if (!isset($this->activeDungeons[$playerName])) {
            return;
        }

        $data = $this->activeDungeons[$playerName];

        // Despawn all remaining dungeon entities
        foreach (array_keys($data["spawned_entities"]) as $entityId) {
            unset($this->dungeonNpcs[$entityId], $this->bossEntities[$entityId]);
            $this->despawnEntity((int) $entityId);
        }

        unset($this->activeDungeons[$playerName]);
    }

    private function despawnEntity(int $entityId): void
    {
        foreach ($this->getServer()->getWorldManager()->getWorlds() as $world) {
            $entity = $world->getEntity($entityId);
            if ($entity !== null) {
                try {
                    if (!$entity->isClosed()) {
                        $entity->close();
                    }
                } catch (\Throwable $e) {
                    // Entity might already be removed — ignore
                }
                return;
            }
        }
    }

    // ─── Event Handlers ────────────────────────────────────────────────

    /**
     * Track entities spawned by our dungeon system via SadNPC.
     * We set $spawningFor before calling spawnNPCAt, so this handler
     * can identify which entities belong to which dungeon.
     */
    public function onEntitySpawn(EntitySpawnEvent $event): void
    {
        $entity = $event->getEntity();
        if (!$entity instanceof Living) {
            return;
        }

        // Only process if we're actively spawning for a dungeon
        $playerName = $this->spawningFor;
        if ($playerName === "" || !isset($this->activeDungeons[$playerName])) {
            return;
        }

        $entityId = $entity->getId();

        // Verify entity spawned near the dungeon area (safety check)
        $pos = $entity->getPosition();
        $data = $this->activeDungeons[$playerName];
        $dx = abs($pos->getX() - $data["dungeon_x"]);
        $dz = abs($pos->getZ() - $data["dungeon_z"]);
        if ($dx > 80 || $dz > 80) {
            return; // Too far from dungeon centre — not ours
        }

        // Track the entity
        if ($this->spawningBoss) {
            $this->bossEntities[$entityId] = $playerName;

            // For tier-5 dungeons, the boss gets 2× health
            if ($data["tier"] >= 5) {
                $maxHp = $entity->getMaxHealth();
                $entity->setMaxHealth($maxHp * 2);
                $entity->setHealth($maxHp * 2);
            }
        } else {
            $this->dungeonNpcs[$entityId] = $playerName;
        }

        // Add to spawned_entities for cleanup
        $this->activeDungeons[$playerName]["spawned_entities"][$entityId] = true;
    }

    /**
     * Handle entity death — check if it's a dungeon NPC or boss.
     * SadNPC also listens to this event and gives its own rewards;
     * our handler only tracks dungeon progression.
     */
    public function onEntityDeath(EntityDeathEvent $event): void
    {
        $entity   = $event->getEntity();
        $entityId = $entity->getId();

        // ── Tracked dungeon NPC ────────────────────────────────────
        if (isset($this->dungeonNpcs[$entityId])) {
            $playerName = $this->dungeonNpcs[$entityId];
            unset($this->dungeonNpcs[$entityId]);

            // Remove from spawned_entities tracking
            if (isset($this->activeDungeons[$playerName])) {
                unset($this->activeDungeons[$playerName]["spawned_entities"][$entityId]);
            }

            // Determine who killed the NPC
            $killer = $this->getKillingPlayer($entity);
            if ($killer !== null && strtolower($killer->getName()) === $playerName) {
                $this->handleNpcKill($killer);
            } else {
                // NPC died from other causes — still count for the dungeon owner
                $owner = $this->getServer()->getPlayerByPrefix($playerName);
                if ($owner !== null && isset($this->activeDungeons[$playerName])) {
                    $this->handleNpcKill($owner);
                }
            }
            return;
        }

        // ── Tracked boss entity ────────────────────────────────────
        if (isset($this->bossEntities[$entityId])) {
            $playerName = $this->bossEntities[$entityId];
            unset($this->bossEntities[$entityId]);

            if (isset($this->activeDungeons[$playerName])) {
                unset($this->activeDungeons[$playerName]["spawned_entities"][$entityId]);
            }

            $killer = $this->getKillingPlayer($entity);
            if ($killer !== null && strtolower($killer->getName()) === $playerName) {
                $this->handleBossKill($killer);
            } else {
                $owner = $this->getServer()->getPlayerByPrefix($playerName);
                if ($owner !== null && isset($this->activeDungeons[$playerName])) {
                    $this->handleBossKill($owner);
                }
            }
            return;
        }

        // ── Fallback: untracked NPC killed by a dungeon player ─────
        $killer = $this->getKillingPlayer($entity);
        if ($killer !== null) {
            $killerName = strtolower($killer->getName());
            if (isset($this->activeDungeons[$killerName])) {
                $data      = $this->activeDungeons[$killerName];
                $entityPos = $entity->getPosition();
                $dx = abs($entityPos->getX() - $data["dungeon_x"]);
                $dz = abs($entityPos->getZ() - $data["dungeon_z"]);
                if ($dx < 80 && $dz < 80) {
                    // Entity was in the dungeon area — treat as dungeon kill
                    $this->handleNpcKill($killer);
                }
            }
        }
    }

    /**
     * Clean up dungeon when a player disconnects.
     */
    public function onPlayerQuit(PlayerQuitEvent $event): void
    {
        $playerName = strtolower($event->getPlayer()->getName());
        if (isset($this->activeDungeons[$playerName])) {
            $this->cleanupDungeon($playerName);
        }
        // Clean up stale cooldowns
        if (isset($this->cooldowns[$playerName])) {
            $settings    = $this->getConfig()->get("settings", []);
            $cooldownSec = (int) ($settings["cooldown"] ?? 300);
            if (time() - $this->cooldowns[$playerName] >= $cooldownSec) {
                unset($this->cooldowns[$playerName]);
            }
        }
    }

    /**
     * Respawn dungeon players at the dungeon position.
     */
    public function onPlayerRespawn(PlayerRespawnEvent $event): void
    {
        $playerName = strtolower($event->getPlayer()->getName());
        if (!isset($this->activeDungeons[$playerName])) {
            return;
        }

        $data  = $this->activeDungeons[$playerName];
        $world = $this->getServer()->getWorldManager()->getWorldByName($data["world_name"]);
        if ($world !== null) {
            $pos = new Position($data["dungeon_x"], self::DUNGEON_Y, $data["dungeon_z"], $world);
            $event->setRespawnPosition($pos);
        }
    }

    /**
     * If a player logs in and is stuck in the dungeon area without an active
     * dungeon (e.g. they disconnected earlier), teleport them out.
     */
    public function onPlayerJoin(PlayerJoinEvent $event): void
    {
        $player     = $event->getPlayer();
        $playerName = strtolower($player->getName());

        if (isset($this->activeDungeons[$playerName])) {
            // Player has an active dungeon — they reconnected
            $data = $this->activeDungeons[$playerName];
            $player->sendMessage(TF::YELLOW . "Вы вернулись в подземелье «{$data["dungeon_name"]}»!");
            $player->sendMessage(
                TF::AQUA . "Комната {$data["current_room"]}/{$data["total_rooms"]} | NPC осталось: {$data["npc_remaining"]}"
            );
            return;
        }

        // Check if player is in the dungeon area without an active dungeon
        $pos = $player->getPosition();
        if ($pos->getX() > self::DUNGEON_BASE_X - 1000) {
            $defaultWorld = $this->getServer()->getWorldManager()->getDefaultWorld();
            if ($defaultWorld !== null) {
                $player->teleport($defaultWorld->getSafeSpawn());
                $player->sendMessage(TF::YELLOW . "Вы были возвращены из незавершённого подземелья.");
            }
        }
    }

    // ─── Timeout Checking ──────────────────────────────────────────────

    private function checkTimeouts(): void
    {
        $settings       = $this->getConfig()->get("settings", []);
        $globalTimeout  = (int) ($settings["timeout"] ?? 1800);
        $roomTimeout    = (int) ($settings["room_timeout"] ?? 120);
        $now            = time();

        foreach (array_keys($this->activeDungeons) as $playerName) {
            $data = $this->activeDungeons[$playerName] ?? null;
            if ($data === null) {
                continue;
            }

            $player = $this->getServer()->getPlayerByPrefix($playerName);
            if ($player === null) {
                // Player went offline — clean up
                $this->cleanupDungeon($playerName);
                continue;
            }

            // Global dungeon timeout
            if ($now - $data["start_time"] >= $globalTimeout) {
                $this->failDungeon($player, "Время подземелья истекло!");
                continue;
            }

            // Per-room timeout
            $roomStart = $data["room_start_time"] ?? $data["start_time"];
            $roomElapsed = $now - $roomStart;

            if ($roomElapsed >= $roomTimeout) {
                $this->failDungeon($player, "Время комнаты истекло!");
            } elseif ($roomElapsed >= $roomTimeout - 30 && $roomElapsed < $roomTimeout - 25) {
                // Warning at 30 seconds remaining
                $left = $roomTimeout - $roomElapsed;
                $player->sendMessage(TF::RED . "⚠ Внимание! Осталось {$left} сек. на комнату!");
            }
        }
    }

    // ─── Utility Methods ───────────────────────────────────────────────

    private function getKillingPlayer(Entity $entity): ?Player
    {
        $lastDamage = $entity->getLastDamageCause();
        if ($lastDamage instanceof EntityDamageByEntityEvent) {
            $damager = $lastDamage->getDamager();
            if ($damager instanceof Player) {
                return $damager;
            }
        }
        return null;
    }

    /**
     * Distribute total NPCs across rooms. Extra NPCs go to earlier rooms.
     * Example: 8 NPCs / 5 rooms = [2, 2, 2, 1, 1]
     */
    private function getNpcsPerRoom(int $totalNpcs, int $totalRooms, int $currentRoom): int
    {
        if ($totalRooms <= 0) {
            return $totalNpcs;
        }
        $base  = intdiv($totalNpcs, $totalRooms);
        $extra = $totalNpcs % $totalRooms;
        return $base + ($currentRoom <= $extra ? 1 : 0);
    }

    /**
     * Parse loot table entries in format "item_name:meta:count:chance"
     * and return an array of Item objects that passed the chance roll.
     */
    private function parseLootTable(array $entries): array
    {
        $items  = [];
        $parser = StringToItemParser::getInstance();

        foreach ($entries as $entry) {
            $parts = explode(":", (string) $entry);
            if (count($parts) < 4) {
                continue;
            }

            $itemName = $parts[0];
            // $meta = (int) $parts[1]; — not used in PMMP 5.x item system
            $maxCount = (int) $parts[2];
            $chance   = (float) $parts[3];

            // Roll against chance
            if (mt_rand() / mt_getrandmax() > $chance) {
                continue;
            }

            $count = mt_rand(1, max($maxCount, 1));

            // Try parsing item name
            $item = $parser->parse($itemName);
            if ($item === null) {
                // Fallback: try with minecraft: prefix
                $item = $parser->parse("minecraft:" . $itemName);
            }
            if ($item !== null && !$item->isNull()) {
                $item->setCount($count);
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Give loot items to a player. Overflow items are dropped on the ground.
     * Applies SadCore's loot multiplier if available.
     */
    private function giveLoot(Player $player, array $lootEntries): void
    {
        // Apply loot multiplier from SadCore
        $sadCore     = $this->getServer()->getPluginManager()->getPlugin("SadCore");
        $multiplier  = 1.0;
        if ($sadCore !== null && $sadCore->isEnabled() && method_exists($sadCore, "getLootMultiplier")) {
            $multiplier = $sadCore->getLootMultiplier($player->getName());
        }

        $items = $this->parseLootTable($lootEntries);

        // Apply multiplier: if multiplier > 1.0, roll extra times
        if ($multiplier > 1.0 && count($items) > 0) {
            $extraRolls = (int) ($multiplier - 1.0);
            for ($i = 0; $i < $extraRolls; $i++) {
                $extraItems = $this->parseLootTable($lootEntries);
                $items = array_merge($items, $extraItems);
            }
        }

        if (count($items) === 0) {
            $player->sendMessage(TF::GRAY . "Лут не выпал...");
            return;
        }

        $player->sendMessage(TF::GOLD . "Получен лут:");
        foreach ($items as $item) {
            $itemName = $item->getName();
            $count    = $item->getCount();
            $player->sendMessage(TF::YELLOW . "  + {$itemName} x{$count}");

            // Add to inventory; drop overflow on the ground
            $overflow = $player->getInventory()->addItem($item);
            foreach ($overflow as $dropItem) {
                $player->getWorld()->dropItem($player->getPosition(), $dropItem);
            }
        }
    }
}
