<?php

declare(strict_types=1);

namespace sadcraft\events;

use pocketmine\entity\Living;
use pocketmine\entity\Location;
use pocketmine\entity\SizeInfo;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;

class RaidBossEntity extends Living
{
    /** @var RaidBossEntity|null Reference to the currently active boss (for Main to track). */
    private static ?RaidBossEntity $activeBoss = null;

    private int $attackCooldown = 0;
    private int $tickCounter    = 0;

    /* ──────────────────────────────────────────────────────────────────
     *  Entity metadata
     * ────────────────────────────────────────────────────────────────── */

    public static function getNetworkTypeId(): string
    {
        // Client renders as zombie; custom name-tag makes it visually distinct.
        return "minecraft:zombie";
    }

    protected function getInitialSizeInfo(): SizeInfo
    {
        return new SizeInfo(0.6, 1.8, 1.62);
    }

    public function getName(): string
    {
        return "Raid Boss";
    }

    /**
     * Raid bosses should never persist across restarts — they are event-only.
     */
    public function canSaveWithChunk(): bool
    {
        return false;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Static boss tracker
     * ────────────────────────────────────────────────────────────────── */

    public static function setActiveBoss(?RaidBossEntity $boss): void
    {
        self::$activeBoss = $boss;
    }

    public static function getActiveBoss(): ?RaidBossEntity
    {
        return self::$activeBoss;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Initialisation
     * ────────────────────────────────────────────────────────────────── */

    protected function initEntity(CompoundTag $nbt): void
    {
        parent::initEntity($nbt);

        $main   = Main::getInstance();
        $health = $main !== null
            ? ($main->getConfig()->getNested("raid.boss_health", 500) ?? 500)
            : 500;
        $bossName = $main !== null
            ? ($main->getConfig()->getNested("raid.boss_name", "§c§lХРАНИТЕЛЬ ПУСТОШИ") ?? "§c§lХРАНИТЕЛЬ ПУСТОШИ")
            : "§c§lХРАНИТЕЛЬ ПУСТОШИ";

        $this->setMaxHealth($health);
        $this->setHealth($health);
        $this->setNameTag($bossName);
        $this->setNameTagAlwaysVisible(true);

        // Prevent the boss from being pushed around too easily
        $this->setImmobile(false);
    }

    /* ──────────────────────────────────────────────────────────────────
     *  AI — runs every tick
     * ────────────────────────────────────────────────────────────────── */

    protected function entityBaseTick(int $tickDiff = 1): bool
    {
        $hasUpdate = parent::entityBaseTick($tickDiff);

        if (!$this->isAlive()) {
            return $hasUpdate;
        }

        $this->tickCounter    += $tickDiff;
        $this->attackCooldown  = max(0, $this->attackCooldown - $tickDiff);

        // Run AI logic every 10 ticks (0.5 s) to avoid heavy per-tick processing
        if ($this->tickCounter % 10 !== 0) {
            return $hasUpdate;
        }

        $target = $this->findNearestPlayer(50.0);
        if ($target === null) {
            return $hasUpdate;
        }

        $myPos    = $this->getPosition();
        $theirPos = $target->getPosition();
        $distance = $myPos->distance($theirPos);

        // ── Face the target ──
        $this->lookAt($theirPos);

        $main   = Main::getInstance();
        $damage = $main !== null
            ? ($main->getConfig()->getNested("raid.boss_damage", 18) ?? 18)
            : 18;

        // ── Attack when in melee range ──
        if ($distance <= 2.5 && $this->attackCooldown <= 0) {
            $ev = new EntityDamageByEntityEvent(
                $this,
                $target,
                EntityDamageEvent::CAUSE_ENTITY_ATTACK,
                (float) $damage
            );
            $target->attack($ev);
            $this->attackCooldown = 20; // 1-second cooldown (20 ticks)
        }

        // ── Move toward the target ──
        if ($distance > 2.5) {
            $direction = $theirPos->subtractVector($myPos)->normalize();
            $speed     = 0.4;
            $this->setMotion(new Vector3(
                $direction->x * $speed,
                $this->getMotion()->y, // preserve vertical motion (gravity)
                $direction->z * $speed
            ));
        }

        return $hasUpdate;
    }

    /**
     * Find the closest alive player within $maxDistance blocks.
     */
    private function findNearestPlayer(float $maxDistance): ?Player
    {
        $nearest     = null;
        $nearestDist = $maxDistance;

        foreach ($this->getWorld()->getPlayers() as $player) {
            if (!$player->isAlive()) {
                continue;
            }
            $dist = $this->getPosition()->distance($player->getPosition());
            if ($dist < $nearestDist) {
                $nearest     = $player;
                $nearestDist = $dist;
            }
        }

        return $nearest;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Drops & XP
     * ────────────────────────────────────────────────────────────────── */

    public function getDrops(): array
    {
        // Drops are injected by Main::onEntityDeath() from config.
        // Returning empty here avoids double-drops.
        return [];
    }

    public function getXpDropAmount(): int
    {
        return 200;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Death
     * ────────────────────────────────────────────────────────────────── */

    protected function onDeath(): void
    {
        parent::onDeath();
        // Loot & broadcast are handled by Main::onEntityDeath().
    }
}
