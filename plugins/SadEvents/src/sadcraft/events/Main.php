<?php

declare(strict_types=1);

namespace sadcraft\events;

use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\scheduler\ClosureTask;
use pocketmine\player\Player;
use pocketmine\world\World;
use pocketmine\world\Position;
use pocketmine\world\sound\ExplodeSound;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\entity\Living;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\math\Vector3;
use pocketmine\block\VanillaBlocks;
use pocketmine\block\tile\Chest as ChestTile;
use pocketmine\nbt\tag\CompoundTag;

class Main extends PluginBase implements Listener
{
    private static ?Main $instance = null;

    private bool $bloodMoonActive = false;
    private ?string $activeEvent = null;
    private int $raidGeneration = 0;
    private ?int $raidBossEntityId = null;
    private array $configData;

    /* ──────────────────────────────────────────────────────────────────
     *  Lifecycle
     * ────────────────────────────────────────────────────────────────── */

    public function onEnable(): void
    {
        self::$instance = $this;
        $this->saveDefaultConfig();
        $this->configData = $this->getConfig()->getAll();

        // PM5 не поддерживает Entity::registerEntity() — используем стандартного зомби для рейд-босса

        // Register event listener
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        // Start automatic event cycle
        $interval = ($this->configData["auto_event_interval"] ?? 600) * 20; // seconds → ticks
        $this->getScheduler()->scheduleRepeatingTask(
            new ClosureTask(fn() => $this->triggerRandomEvent()),
            $interval
        );

        $this->getLogger()->info("SadEvents enabled — world events are active!");
    }

    public function onDisable(): void
    {
        self::$instance = null;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Static API  (used by SadCore / SadNPC / other plugins)
     * ────────────────────────────────────────────────────────────────── */

    public static function getInstance(): ?Main
    {
        return self::$instance;
    }

    /**
     * Returns true while the Blood Moon event is active.
     * SadNPC / SadCore should query this flag to boost mob spawns.
     */
    public static function isBloodMoon(): bool
    {
        return self::$instance !== null && self::$instance->bloodMoonActive;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Auto-event cycle
     * ────────────────────────────────────────────────────────────────── */

    private function triggerRandomEvent(): void
    {
        if ($this->activeEvent !== null) {
            return; // another event already running
        }

        if (count($this->getServer()->getOnlinePlayers()) === 0) {
            return;
        }

        $events = ["bloodmoon", "supply", "meteor", "raid"];
        $this->startEvent($events[array_rand($events)]);
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Generic event starter
     * ────────────────────────────────────────────────────────────────── */

    public function startEvent(string $type): bool
    {
        if ($this->activeEvent !== null) {
            return false;
        }

        return match ($type) {
            "bloodmoon" => $this->startBloodMoon(),
            "supply"    => $this->startSupplyDrop(),
            "meteor"    => $this->startMeteor(),
            "raid"      => $this->startRaid(),
            default     => false,
        };
    }

    /* ══════════════════════════════════════════════════════════════════
     *  BLOOD MOON
     * ══════════════════════════════════════════════════════════════════ */

    private function startBloodMoon(): bool
    {
        $this->bloodMoonActive = true;
        $this->activeEvent     = "bloodmoon";

        $cfg     = $this->configData["bloodmoon"];
        $message = $cfg["message_start"] ?? "§c§l[КРОВАВАЯ ЛУНА] §r§7Мобы стали сильнее, но лут богаче!";
        $this->getServer()->broadcastMessage($message);

        // Schedule automatic end
        $duration = ($cfg["duration"] ?? 300) * 20;
        $this->getScheduler()->scheduleDelayedTask(
            new ClosureTask(fn() => $this->endBloodMoon()),
            $duration
        );

        return true;
    }

    private function endBloodMoon(): void
    {
        if (!$this->bloodMoonActive) {
            return;
        }

        $this->bloodMoonActive = false;
        $this->activeEvent     = null;

        $cfg     = $this->configData["bloodmoon"];
        $message = $cfg["message_end"] ?? "§a§l[КРОВАВАЯ ЛУНА] §r§7Кровавая луна закончилась.";
        $this->getServer()->broadcastMessage($message);
    }

    /** Damage multiplier that hostile mobs deal during Blood Moon. */
    public function getBloodMoonDamageMultiplier(): float
    {
        return $this->bloodMoonActive
            ? ($this->configData["bloodmoon"]["damage_multiplier"] ?? 1.5)
            : 1.0;
    }

    /** Loot multiplier for mob drops during Blood Moon. */
    public function getBloodMoonLootMultiplier(): float
    {
        return $this->bloodMoonActive
            ? ($this->configData["bloodmoon"]["loot_multiplier"] ?? 2.0)
            : 1.0;
    }

    /** Mob-spawn multiplier during Blood Moon (for SadNPC). */
    public function getBloodMoonMobSpawnMultiplier(): int
    {
        return $this->bloodMoonActive
            ? ($this->configData["bloodmoon"]["mob_spawn_multiplier"] ?? 3)
            : 1;
    }

    /* ══════════════════════════════════════════════════════════════════
     *  SUPPLY DROP
     * ══════════════════════════════════════════════════════════════════ */

    private function startSupplyDrop(): bool
    {
        $online = $this->getServer()->getOnlinePlayers();
        if (count($online) === 0) {
            return false;
        }

        $player = $online[array_rand($online)];
        $world  = $player->getWorld();
        $cfg    = $this->configData["supply"];
        $radius = $cfg["broadcast_radius"] ?? 500;

        $pos = $this->getRandomSurfacePosition($world, $player->getPosition(), $radius);
        if ($pos === null) {
            return false;
        }

        // ── Place chest block ──
        $blockPos = new Vector3((int) $pos->x, (int) $pos->y, (int) $pos->z);
        $world->setBlock($blockPos, VanillaBlocks::CHEST());

        // ── Create tile & fill inventory ──
        $chestTile = new ChestTile($world, $blockPos);
        $world->addTile($chestTile);

        $inventory = $chestTile->getInventory();
        $slot      = 0;

        foreach ($cfg["loot_tiers"] as $tier) {
            $chance = (float) ($tier["chance"] ?? 0);
            if (mt_rand() / mt_getrandmax() < $chance) {
                foreach ($tier["items"] as $itemStr) {
                    $item = $this->parseItemString($itemStr);
                    if ($item !== null && $slot < $inventory->getSize()) {
                        $inventory->setItem($slot, $item);
                        $slot++;
                    }
                }
            }
        }

        // ── Broadcast ──
        $tpl     = $cfg["message"] ?? "§e§l[СНАБЖЕНИЕ] §r§7Контейнер с припасами упал на §bX:{x} Z:{z}§7!";
        $message = str_replace(["{x}", "{z}"], [(int) $pos->x, (int) $pos->z], $tpl);
        $this->getServer()->broadcastMessage($message);

        $this->activeEvent = "supply";

        // Supply event flag clears after 5 minutes (chest stays)
        $this->getScheduler()->scheduleDelayedTask(
            new ClosureTask(fn() => $this->clearEventIf("supply")),
            6000
        );

        return true;
    }

    /* ══════════════════════════════════════════════════════════════════
     *  METEOR
     * ══════════════════════════════════════════════════════════════════ */

    private function startMeteor(): bool
    {
        $online = $this->getServer()->getOnlinePlayers();
        if (count($online) === 0) {
            return false;
        }

        $player = $online[array_rand($online)];
        $world  = $player->getWorld();

        $pos = $this->getRandomSurfacePosition($world, $player->getPosition(), 300);
        if ($pos === null) {
            return false;
        }

        $cfg    = $this->configData["meteor"];
        $radius = (int) ($cfg["radius"] ?? 5);

        $cx = (int) $pos->x;
        $cy = (int) $pos->y;
        $cz = (int) $pos->z;

        // ── Carve crater ──
        for ($x = $cx - $radius; $x <= $cx + $radius; $x++) {
            for ($y = $cy - $radius; $y <= $cy + $radius; $y++) {
                for ($z = $cz - $radius; $z <= $cz + $radius; $z++) {
                    $dist = sqrt(($x - $cx) ** 2 + ($y - $cy) ** 2 + ($z - $cz) ** 2);
                    if ($dist > $radius) {
                        continue;
                    }
                    $vec = new Vector3($x, $y, $z);
                    if ($dist <= $radius * 0.35) {
                        // Inner core — lava
                        $world->setBlock($vec, VanillaBlocks::LAVA());
                    } elseif ($dist <= $radius * 0.6 && $y <= $cy - 1) {
                        // Floor — obsidian ring
                        $world->setBlock($vec, VanillaBlocks::OBSIDIAN());
                    } else {
                        // Outer shell — air
                        $world->setBlock($vec, VanillaBlocks::AIR());
                    }
                }
            }
        }

        // ── Drop loot items ──
        $lootItems = $cfg["loot_items"] ?? [];
        $dropPos   = new Vector3($cx + 0.5, $cy + 1, $cz + 0.5);
        foreach ($lootItems as $itemStr) {
            $item = $this->parseItemString($itemStr);
            if ($item !== null) {
                $world->dropItem($dropPos, $item);
            }
        }

        // ── Sound effect ──
        $world->addSound(new Vector3($cx, $cy, $cz), new ExplodeSound());

        // ── Broadcast ──
        $tpl     = $cfg["message"] ?? "§6§l[МЕТЕОРИТ] §r§7Метеорит упал на §bX:{x} Z:{z}§7! Внутри — редкие ресурсы!";
        $message = str_replace(["{x}", "{z}"], [$cx, $cz], $tpl);
        $this->getServer()->broadcastMessage($message);

        $this->activeEvent = "meteor";

        $this->getScheduler()->scheduleDelayedTask(
            new ClosureTask(fn() => $this->clearEventIf("meteor")),
            2400 // 2 min
        );

        return true;
    }

    /* ══════════════════════════════════════════════════════════════════
     *  RAID BOSS
     * ══════════════════════════════════════════════════════════════════ */

    private function startRaid(): bool
    {
        $online = $this->getServer()->getOnlinePlayers();
        if (count($online) === 0) {
            return false;
        }

        $player = $online[array_rand($online)];
        $world  = $player->getWorld();

        $pos = $this->getRandomSurfacePosition($world, $player->getPosition(), 100);
        if ($pos === null) {
            return false;
        }

        $cfg = $this->configData["raid"];

        // ── Spawn raid-boss as a powered-up Zombie ──
        $location = new Location($pos->x + 0.5, $pos->y, $pos->z + 0.5, $world, 0, 0);
        $nbt      = CompoundTag::create();

        $boss = new \pocketmine\entity\Zombie($location, $nbt);
        $bossHealth = $cfg["boss_health"] ?? 500;
        $boss->setMaxHealth($bossHealth);
        $boss->setHealth($bossHealth);
        $bossName = $cfg["boss_name"] ?? "§c§lХРАНИТЕЛЬ ПУСТОШИ";
        $boss->setNameTag($bossName);
        $boss->setNameTagAlwaysVisible(true);
        $boss->spawnToAll();

        $this->raidBossEntityId = $boss->getId();
        $this->activeEvent = "raid";

        // ── Broadcast ──
        $tpl     = $cfg["message"] ?? "§5§l[РЕЙД] §r§7Хранитель Пустоши появился на §bX:{x} Z:{z}§7!";
        $message = str_replace(["{x}", "{z}"], [(int) $pos->x, (int) $pos->z], $tpl);
        $this->getServer()->broadcastMessage($message);

        // ── Raid timeout (15 min) — boss vanishes if not killed ──
        $this->raidGeneration++;
        $generation = $this->raidGeneration;

        $this->getScheduler()->scheduleDelayedTask(
            new ClosureTask(function () use ($generation): void {
                if ($generation !== $this->raidGeneration || $this->activeEvent !== "raid") {
                    return;
                }
                $boss = $this->raidBossEntityId !== null ? $world->getEntity($this->raidBossEntityId) : null;
                if ($boss !== null && $boss->isAlive()) {
                    $boss->flagForDespawn();
                }
                $this->raidBossEntityId = null;
                $this->activeEvent = null;
                $this->getServer()->broadcastMessage("§5§l[РЕЙД] §r§7Хранитель Пустоши исчез в тумане...");
            }),
            18000 // 15 min
        );

        return true;
    }

    /**
     * Called from the EntityDeathEvent handler when the raid boss dies.
     */
    public function onRaidBossDeath(?Player $killer): void
    {
        $cfg = $this->configData["raid"];

        $playerName = $killer !== null ? $killer->getName() : "Неизвестный";
        $tpl        = $cfg["defeat_message"] ?? "§5§l[РЕЙД] §r§f{player} §7убил Хранителя Пустоши!";
        $message    = str_replace("{player}", $playerName, $tpl);
        $this->getServer()->broadcastMessage($message);

        // Increment generation so the timeout task becomes a no-op
        $this->raidGeneration++;

        $this->raidBossEntityId = null;
        $this->activeEvent = null;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Event listeners
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Blood Moon — multiply damage dealt by hostile mobs to players.
     *
     * @priority HIGHEST
     */
    public function onEntityDamageByEntity(EntityDamageByEntityEvent $event): void
    {
        if ($event->isCancelled() || !$this->bloodMoonActive) {
            return;
        }

        $damager = $event->getDamager();
        $target  = $event->getEntity();

        // Non-player entity → player : boost damage
        if ($target instanceof Player && !($damager instanceof Player)) {
            $multiplier = $this->configData["bloodmoon"]["damage_multiplier"] ?? 1.5;
            $event->setBaseDamage($event->getBaseDamage() * $multiplier);
        }
    }

    /**
     * Handle mob death (Blood Moon loot boost) and Raid Boss death (custom loot + broadcast).
     */
    public function onEntityDeath(EntityDeathEvent $event): void
    {
        $entity = $event->getEntity();

        // ── Raid boss death ──
        if ($this->raidBossEntityId !== null && $entity->getId() === $this->raidBossEntityId) {
            $killer = null;
            $lastDamage = $entity->getLastDamageCause();
            if ($lastDamage instanceof EntityDamageByEntityEvent) {
                $damager = $lastDamage->getDamager();
                if ($damager instanceof Player) {
                    $killer = $damager;
                }
            }

            // Replace drops with raid loot from config
            $lootConfig = $this->configData["raid"]["loot"] ?? [];
            $drops      = [];
            foreach ($lootConfig as $itemStr) {
                $item = $this->parseItemString($itemStr);
                if ($item !== null) {
                    $drops[] = $item;
                }
            }
            $event->setDrops($drops);

            $this->onRaidBossDeath($killer);
            return;
        }

        // ── Blood Moon loot boost ──
        if ($this->bloodMoonActive && !($entity instanceof Player)) {
            $multiplier = $this->configData["bloodmoon"]["loot_multiplier"] ?? 2.0;
            $drops = $event->getDrops();
            foreach ($drops as $item) {
                $newCount = (int) max(1, round($item->getCount() * $multiplier));
                $item->setCount($newCount);
            }
            $event->setDrops($drops);
        }
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Commands
     * ────────────────────────────────────────────────────────────────── */

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        return match ($command->getName()) {
            "event"      => $this->onEventCommand($sender),
            "eventstart" => $this->onEventStartCommand($sender, $args),
            default      => false,
        };
    }

    private function onEventCommand(CommandSender $sender): bool
    {
        if ($this->activeEvent === null) {
            $sender->sendMessage("§7Сейчас нет активных ивентов.");
            return true;
        }

        $names = [
            "bloodmoon" => "§cКровавая Луна",
            "supply"    => "§eСнабжение",
            "meteor"    => "§6Метеорит",
            "raid"      => "§5Рейд Босса",
        ];

        $name = $names[$this->activeEvent] ?? $this->activeEvent;
        $sender->sendMessage("§7Текущий ивент: $name");

        if ($this->activeEvent === "bloodmoon") {
            $dmg  = $this->configData["bloodmoon"]["damage_multiplier"] ?? 1.5;
            $loot = $this->configData["bloodmoon"]["loot_multiplier"] ?? 2.0;
            $spawn = $this->configData["bloodmoon"]["mob_spawn_multiplier"] ?? 3;
            $sender->sendMessage("§7  Урон мобов: §c×$dmg");
            $sender->sendMessage("§7  Лут: §a×$loot");
            $sender->sendMessage("§7  Спавн мобов: §b×$spawn");
        }

        return true;
    }

    private function onEventStartCommand(CommandSender $sender, array $args): bool
    {
        if (count($args) < 1) {
            $sender->sendMessage("§cИспользование: /eventstart <bloodmoon|supply|meteor|raid>");
            return true;
        }

        $type   = strtolower($args[0]);
        $valid  = ["bloodmoon", "supply", "meteor", "raid"];

        if (!in_array($type, $valid, true)) {
            $sender->sendMessage("§cНеизвестный ивент. Доступные: bloodmoon, supply, meteor, raid");
            return true;
        }

        if ($this->activeEvent !== null) {
            $sender->sendMessage("§cИвент уже запущен! Дождитесь окончания текущего.");
            return true;
        }

        if ($this->startEvent($type)) {
            $sender->sendMessage("§aИвент §f$type §aуспешно запущен!");
        } else {
            $sender->sendMessage("§cНе удалось запустить ивент. Возможно, на сервере нет игроков.");
        }

        return true;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Utility helpers
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Find a random surface position within $radius blocks of $center.
     */
    private function getRandomSurfacePosition(World $world, Position $center, int $radius): ?Position
    {
        $x = (int) $center->x + mt_rand(-$radius, $radius);
        $z = (int) $center->z + mt_rand(-$radius, $radius);

        $y = $world->getHighestBlockAt($x, $z);
        if ($y <= 0) {
            return null;
        }

        return new Position($x, $y + 1, $z, $world);
    }

    /**
     * Parse an item string like "diamond:0:4" into a PocketMine Item.
     * Format:  item_name[:meta][:count]
     */
    private function parseItemString(string $str): ?Item
    {
        $parts = explode(":", $str);
        $name  = $parts[0];
        $count = isset($parts[2]) ? (int) $parts[2] : 1;

        $item = StringToItemParser::getInstance()->parse($name);
        if ($item === null) {
            $this->getLogger()->warning("Unknown item in config: \"$name\"");
            return null;
        }

        $item->setCount(max(1, $count));
        return $item;
    }

    /**
     * Clear the active-event flag only if it still matches the expected type.
     * Prevents a stale closure from overwriting a newer event.
     */
    private function clearEventIf(string $expectedType): void
    {
        if ($this->activeEvent === $expectedType) {
            $this->activeEvent = null;
        }
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Public API
     * ────────────────────────────────────────────────────────────────── */

    public function getActiveEvent(): ?string
    {
        return $this->activeEvent;
    }

    public function isEventActive(): bool
    {
        return $this->activeEvent !== null;
    }

    /**
     * Return the whole config array (for inter-plugin access).
     */
    public function getEventConfig(string $key): array
    {
        return $this->configData[$key] ?? [];
    }
}
