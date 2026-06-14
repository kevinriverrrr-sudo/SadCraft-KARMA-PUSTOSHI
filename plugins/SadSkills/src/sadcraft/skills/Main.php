<?php

declare(strict_types=1);

namespace sadcraft\skills;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerItemConsumeEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\inventory\CraftItemEvent;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as TF;
use pocketmine\item\VanillaItems;
use pocketmine\block\VanillaBlocks;
use pocketmine\entity\Living;
use pocketmine\entity\Human;

class Main extends PluginBase implements Listener {

    /** @var Config */
    private Config $skillsData;

    /** @var array */
    private array $xpConfig;

    /** @var array */
    private array $perksConfig;

    /** @var int */
    private int $baseXp;

    /** @var float */
    private float $multiplier;

    /** @var int */
    private int $maxLevel;

    /** @var array<string, string> */
    private array $skillNames = [
        "combat" => "⚔ Бой",
        "mining" => "⛏ Добыча",
        "survival" => "❤ Выживание",
        "stealth" => "🗡 Скрытность",
    ];

    /** @var array<string, string> */
    private array $skillDescriptions = [
        "combat" => "Навык боя — убивайте мобов и игроков для прокачки",
        "mining" => "Навык добычи — копайте руды и блоки для прокачки",
        "survival" => "Навык выживания — ешьте, ловите рыбу и крафтите",
        "stealth" => "Навык скрытности — убивайте в режиме подкрадывания",
    ];

    /** @var array<string, array> Map block types to mining XP keys */
    private array $blockXpMap = [];

    public function onEnable(): void {
        $this->saveResource("config.yml");
        $cfg = new Config($this->getDataFolder() . "config.yml", Config::YAML);

        $this->xpConfig = $cfg->get("xp", []);
        $this->perksConfig = $cfg->get("perks", []);

        $leveling = $cfg->get("leveling", []);
        $this->baseXp = (int) ($leveling["base_xp"] ?? 100);
        $this->multiplier = (float) ($leveling["multiplier"] ?? 1.5);
        $this->maxLevel = (int) ($leveling["max_level"] ?? 50);

        $this->skillsData = new Config($this->getDataFolder() . "skills_data.json", Config::JSON);

        $this->buildBlockXpMap();

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->getLogger()->info("SadSkills загружен! 4 навыка готовы.");
    }

    public function onDisable(): void {
        $this->skillsData->save();
    }

    /**
     * Build a mapping of PocketMine block IDs to mining XP config keys.
     */
    private function buildBlockXpMap(): void {
        // Map VanillaBlocks methods to config keys
        $mapping = [
            "stone" => VanillaBlocks::STONE(),
            "iron_ore" => VanillaBlocks::IRON_ORE(),
            "gold_ore" => VanillaBlocks::GOLD_ORE(),
            "diamond_ore" => VanillaBlocks::DIAMOND_ORE(),
            "emerald_ore" => VanillaBlocks::EMERALD_ORE(),
            "nether_quartz_ore" => VanillaBlocks::NETHER_QUARTZ_ORE(),
            "lapis_ore" => VanillaBlocks::LAPIS_LAZULI_ORE(),
            "obsidian" => VanillaBlocks::OBSIDIAN(),
        ];

        foreach ($mapping as $key => $block) {
            $this->blockXpMap[$block->getStateId()] = $key;
        }
    }

    // ─── Data Access Helpers ─────────────────────────────────────────────

    /**
     * Get the raw skill data array for a player.
     */
    private function getPlayerData(string $playerName): array {
        $lower = strtolower($playerName);
        $data = $this->skillsData->get($lower, []);
        if (empty($data)) {
            $data = [
                "combat" => ["xp" => 0, "level" => 1],
                "mining" => ["xp" => 0, "level" => 1],
                "survival" => ["xp" => 0, "level" => 1],
                "stealth" => ["xp" => 0, "level" => 1],
            ];
            $this->skillsData->set($lower, $data);
        }
        // Ensure all skills exist
        foreach (["combat", "mining", "survival", "stealth"] as $skill) {
            if (!isset($data[$skill])) {
                $data[$skill] = ["xp" => 0, "level" => 1];
            }
        }
        return $data;
    }

    /**
     * Save player data.
     */
    private function savePlayerData(string $playerName, array $data): void {
        $this->skillsData->set(strtolower($playerName), $data);
        $this->skillsData->save();
    }

    /**
     * Calculate XP needed to reach the next level from current level.
     * Formula: xp_needed = base_xp * (level ^ multiplier)
     */
    public function getXpForLevel(int $level): int {
        return (int) round($this->baseXp * pow($level, $this->multiplier));
    }

    /**
     * Add XP to a player's skill and handle level-ups.
     * Returns true if a level-up occurred.
     */
    private function addXp(string $playerName, string $skill, int $amount): bool {
        if ($amount <= 0) {
            return false;
        }

        $data = $this->getPlayerData($playerName);
        $skillData = $data[$skill];
        $skillData["xp"] += $amount;

        $leveledUp = false;

        while ($skillData["level"] < $this->maxLevel) {
            $xpNeeded = $this->getXpForLevel($skillData["level"]);
            if ($skillData["xp"] >= $xpNeeded) {
                $skillData["xp"] -= $xpNeeded;
                $skillData["level"]++;
                $leveledUp = true;
            } else {
                break;
            }
        }

        // At max level, cap XP at 0
        if ($skillData["level"] >= $this->maxLevel) {
            $skillData["level"] = $this->maxLevel;
            $skillData["xp"] = 0;
        }

        $data[$skill] = $skillData;
        $this->savePlayerData($playerName, $data);

        // Notify player of level-up
        if ($leveledUp) {
            $player = $this->getServer()->getPlayerByPrefix($playerName);
            if ($player !== null) {
                $skillDisplay = $this->skillNames[$skill] ?? $skill;
                $player->sendMessage(TF::GOLD . "★ " . TF::YELLOW . "Навык " . $skillDisplay . TF::YELLOW . " повышен до уровня " . TF::WHITE . $skillData["level"] . TF::GOLD . " ★");

                // Check for perk unlock
                $perkMsg = $this->getPerkUnlockMessage($skill, $skillData["level"]);
                if ($perkMsg !== "") {
                    $player->sendMessage(TF::GREEN . "  ↳ " . $perkMsg);
                }
            }
        }

        return $leveledUp;
    }

    /**
     * Get a message describing perks unlocked at a given level.
     */
    private function getPerkUnlockMessage(string $skill, int $level): string {
        $perks = $this->perksConfig[$skill][$level] ?? [];
        if (empty($perks)) {
            return "";
        }

        $parts = [];
        $perkDisplayNames = [
            "damage_bonus" => "бонус урона",
            "crit_chance" => "шанс крита",
            "fortune_bonus" => "бонус удачи",
            "speed_bonus" => "бонус скорости",
            "double_ore_chance" => "шанс двойной руды",
            "heal_bonus" => "бонус лечения",
            "reduced_hunger" => "снижение голода",
            "resistance_chance" => "шанс сопротивления",
            "move_speed" => "скорость передвижения",
            "backstab_damage" => "урон удара в спину",
            "invis_duration" => "длительность невидимости",
        ];

        foreach ($perks as $perk => $value) {
            $name = $perkDisplayNames[$perk] ?? $perk;
            if (is_float($value)) {
                $parts[] = $name . ": " . ($value * 100) . "%";
            } elseif (is_int($value)) {
                $parts[] = $name . ": " . $value;
            } else {
                $parts[] = $name . ": " . $value;
            }
        }

        return TF::AQUA . "Открыты перки: " . TF::WHITE . implode(", ", $parts);
    }

    // ─── XP Bar Rendering ────────────────────────────────────────────────

    /**
     * Render an XP progress bar.
     */
    private function renderXpBar(int $currentXp, int $xpNeeded, int $barLength = 10): string {
        if ($xpNeeded <= 0) {
            $progress = 1.0;
        } else {
            $progress = min(1.0, $currentXp / $xpNeeded);
        }

        $filled = (int) round($progress * $barLength);
        $empty = $barLength - $filled;

        return TF::GRAY . "[" . TF::YELLOW . str_repeat("█", $filled) . TF::DARK_GRAY . str_repeat("░", $empty) . TF::GRAY . "]";
    }

    // ─── Event Handlers ──────────────────────────────────────────────────

    /**
     * Handle entity death — award combat XP (and stealth bonus if sneaking).
     */
    public function onEntityDeath(EntityDeathEvent $event): void {
        $entity = $event->getEntity();
        $damageEvent = $entity->getLastDamageCause();

        if (!$damageEvent instanceof EntityDamageByEntityEvent) {
            return;
        }

        $damager = $damageEvent->getDamager();
        if (!$damager instanceof Player) {
            return;
        }

        $playerName = $damager->getName();

        // Determine kill type and award combat XP
        $xpAmount = 0;
        if ($entity instanceof Player) {
            $xpAmount = (int) ($this->xpConfig["combat"]["kill_player"] ?? 30);
        } elseif ($entity instanceof Human) {
            // NPC — a Human that is not a Player
            $xpAmount = (int) ($this->xpConfig["combat"]["kill_npc"] ?? 40);
        } elseif ($entity instanceof Living) {
            $xpAmount = (int) ($this->xpConfig["combat"]["kill_mob"] ?? 15);
        }

        if ($xpAmount > 0) {
            $this->addXp($playerName, "combat", $xpAmount);
        }

        // Stealth bonus: killing while sneaking
        if ($damager->isSneaking()) {
            $stealthXp = (int) ($this->xpConfig["stealth"]["backstab"] ?? 25);
            $this->addXp($playerName, "stealth", $stealthXp);
        }
    }

    /**
     * Handle block breaking — award mining XP.
     */
    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $stateId = $block->getStateId();

        $playerName = $player->getName();
        $xpKey = $this->blockXpMap[$stateId] ?? null;

        if ($xpKey !== null) {
            $xpAmount = (int) ($this->xpConfig["mining"][$xpKey] ?? 0);
            if ($xpAmount > 0) {
                $this->addXp($playerName, "mining", $xpAmount);
            }
        } else {
            // Default stone-like blocks get 1 XP
            $blockName = strtolower($block->getName());
            if (str_contains($blockName, "stone") || str_contains($blockName, "камень")) {
                $xpAmount = (int) ($this->xpConfig["mining"]["stone"] ?? 1);
                if ($xpAmount > 0) {
                    $this->addXp($playerName, "mining", $xpAmount);
                }
            }
        }
    }

    /**
     * Handle item consumption — award survival XP for eating.
     */
    public function onItemConsume(PlayerItemConsumeEvent $event): void {
        $player = $event->getPlayer();
        $item = $event->getItem();

        // Check if item is food
        if ($item->getType()->getNamespaceId() === "minecraft:" && $this->isFoodItem($item->getId())) {
            $xpAmount = (int) ($this->xpConfig["survival"]["eat"] ?? 3);
            $this->addXp($player->getName(), "survival", $xpAmount);
        }
    }

    /**
     * Check if an item ID corresponds to a food item.
     */
    private function isFoodItem(int $itemId): bool {
        // Common food item IDs in PocketMine-MP
        $foodIds = [
            260, // apple
            282, // mushroom_stew
            297, // bread
            319, // porkchop
            320, // cooked_porkchop
            322, // golden_apple
            349, // fish
            350, // cooked_fish
            354, // cake
            357, // cookie
            360, // melon
            363, // beef
            364, // cooked_beef
            365, // chicken
            366, // cooked_chicken
            391, // carrot
            392, // potato
            393, // baked_potato
            396, // golden_carrot
            411, // rabbit
            412, // cooked_rabbit
            413, // rabbit_stew
            459, // beetroot
            573, // dried_kelp
            641, // sweet_berries
            692, // glow_berries
        ];
        return in_array($itemId, $foodIds, true);
    }

    /**
     * Handle player interaction — detect fishing and breeding.
     */
    public function onPlayerInteract(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $item = $player->getInventory()->getItemInHand();

        // Fishing rod interaction
        if ($item->equals(VanillaItems::FISHING_ROD())) {
            $xpAmount = (int) ($this->xpConfig["survival"]["fish"] ?? 8);
            $this->addXp($player->getName(), "survival", $xpAmount);
        }
    }

    /**
     * Handle crafting — award survival XP.
     */
    public function onCraftItem(CraftItemEvent $event): void {
        $player = $event->getPlayer();
        $xpAmount = (int) ($this->xpConfig["survival"]["craft_item"] ?? 2);
        $this->addXp($player->getName(), "survival", $xpAmount);
    }

    // ─── Commands ────────────────────────────────────────────────────────

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage(TF::RED . "Эта команда доступна только игрокам.");
            return true;
        }

        switch ($command->getName()) {
            case "skills":
                return $this->handleSkillsCommand($sender);
            case "skillinfo":
                return $this->handleSkillInfoCommand($sender, $args);
            default:
                return false;
        }
    }

    /**
     * Handle /skills command — display all skills overview.
     */
    private function handleSkillsCommand(Player $player): bool {
        $playerName = $player->getName();
        $data = $this->getPlayerData($playerName);

        $player->sendMessage(TF::DARK_PURPLE . "═══════════════════════════════");
        $player->sendMessage(TF::GOLD . "  ⚔ Навыки " . TF::WHITE . $playerName);
        $player->sendMessage(TF::DARK_PURPLE . "═══════════════════════════════");

        foreach (["combat", "mining", "survival", "stealth"] as $skill) {
            $skillData = $data[$skill];
            $level = (int) $skillData["level"];
            $xp = (int) $skillData["xp"];

            if ($level >= $this->maxLevel) {
                $xpNeeded = 0;
                $xpStr = TF::GREEN . "МАКС";
            } else {
                $xpNeeded = $this->getXpForLevel($level);
                $xpStr = TF::WHITE . $xp . TF::GRAY . "/" . TF::WHITE . $xpNeeded . " XP";
            }

            $bar = $this->renderXpBar($xp, $xpNeeded);
            $displayName = $this->skillNames[$skill] ?? $skill;

            $player->sendMessage(
                TF::WHITE . "  " . $displayName . TF::GRAY . " Ур." . TF::YELLOW . $level . " " . $bar . " " . $xpStr
            );
        }

        $player->sendMessage(TF::DARK_PURPLE . "═══════════════════════════════");
        $player->sendMessage(TF::GRAY . "  Используйте " . TF::WHITE . "/skillinfo <навык>" . TF::GRAY . " для деталей");

        return true;
    }

    /**
     * Handle /skillinfo <skill> command — display detailed skill info.
     */
    private function handleSkillInfoCommand(Player $player, array $args): bool {
        if (empty($args)) {
            $player->sendMessage(TF::RED . "Использование: /skillinfo <навык>");
            $player->sendMessage(TF::GRAY . "Доступные навыки: combat, mining, survival, stealth");
            return true;
        }

        $skillInput = strtolower($args[0]);
        $validSkills = ["combat", "mining", "survival", "stealth"];

        if (!in_array($skillInput, $validSkills, true)) {
            $player->sendMessage(TF::RED . "Неизвестный навык: " . $args[0]);
            $player->sendMessage(TF::GRAY . "Доступные навыки: combat, mining, survival, stealth");
            return true;
        }

        $skill = $skillInput;
        $playerName = $player->getName();
        $data = $this->getPlayerData($playerName);
        $skillData = $data[$skill];
        $level = (int) $skillData["level"];
        $xp = (int) $skillData["xp"];

        $displayName = $this->skillNames[$skill];
        $description = $this->skillDescriptions[$skill] ?? "";

        $player->sendMessage(TF::DARK_PURPLE . "═══════════════════════════════");
        $player->sendMessage(TF::GOLD . "  " . $displayName . TF::GRAY . " — " . TF::WHITE . $description);

        // Level and XP
        if ($level >= $this->maxLevel) {
            $player->sendMessage(TF::WHITE . "  Уровень: " . TF::GREEN . $level . TF::GRAY . " (максимальный)");
            $player->sendMessage(TF::WHITE . "  Опыт: " . TF::GREEN . "МАКСИМАЛЬНЫЙ");
        } else {
            $xpNeeded = $this->getXpForLevel($level);
            $bar = $this->renderXpBar($xp, $xpNeeded);
            $player->sendMessage(TF::WHITE . "  Уровень: " . TF::YELLOW . $level . TF::GRAY . " / " . TF::WHITE . $this->maxLevel);
            $player->sendMessage(TF::WHITE . "  Опыт: " . $bar . " " . TF::WHITE . $xp . TF::GRAY . "/" . TF::WHITE . $xpNeeded . " XP");
        }

        // Current active perks
        $activePerks = $this->getActivePerks($skill, $level);
        if (!empty($activePerks)) {
            $player->sendMessage(TF::GREEN . "  Активные перки:");
            foreach ($activePerks as $perkName => $perkValue) {
                $player->sendMessage(TF::WHITE . "    • " . $this->formatPerk($perkName, $perkValue));
            }
        } else {
            $player->sendMessage(TF::GRAY . "  Нет активных перков");
        }

        // Next milestone
        $nextMilestone = $this->getNextMilestone($skill, $level);
        if ($nextMilestone !== null) {
            $milestonePerks = $this->perksConfig[$skill][$nextMilestone] ?? [];
            $player->sendMessage(TF::AQUA . "  Следующий рубеж: " . TF::YELLOW . "Ур. " . $nextMilestone);
            foreach ($milestonePerks as $perkName => $perkValue) {
                $player->sendMessage(TF::WHITE . "    • " . $this->formatPerk($perkName, $perkValue));
            }
        }

        $player->sendMessage(TF::DARK_PURPLE . "═══════════════════════════════");

        return true;
    }

    /**
     * Get all active perks for a skill at a given level.
     */
    private function getActivePerks(string $skill, int $level): array {
        $perks = $this->perksConfig[$skill] ?? [];
        $active = [];

        foreach ($perks as $milestone => $perkList) {
            if ((int) $milestone <= $level) {
                foreach ($perkList as $perkName => $perkValue) {
                    // Later milestones override earlier ones for the same perk
                    $active[$perkName] = $perkValue;
                }
            }
        }

        return $active;
    }

    /**
     * Get the next milestone level for a skill after the current level.
     */
    private function getNextMilestone(string $skill, int $level): ?int {
        $perks = $this->perksConfig[$skill] ?? [];
        $milestones = array_keys($perks);
        sort($milestones);

        foreach ($milestones as $milestone) {
            if ((int) $milestone > $level) {
                return (int) $milestone;
            }
        }

        return null;
    }

    /**
     * Format a perk for display.
     */
    private function formatPerk(string $perkName, mixed $value): string {
        $perkDisplayNames = [
            "damage_bonus" => "Бонус урона",
            "crit_chance" => "Шанс крита",
            "fortune_bonus" => "Бонус удачи",
            "speed_bonus" => "Бонус скорости",
            "double_ore_chance" => "Шанс двойной руды",
            "heal_bonus" => "Бонус лечения",
            "reduced_hunger" => "Снижение голода",
            "resistance_chance" => "Шанс сопротивления",
            "move_speed" => "Скорость передвижения",
            "backstab_damage" => "Урон удара в спину",
            "invis_duration" => "Длительность невидимости",
        ];

        $display = $perkDisplayNames[$perkName] ?? $perkName;

        if (is_float($value) && $value < 1.0) {
            return $display . ": " . TF::YELLOW . ($value * 100) . "%";
        } elseif (is_float($value)) {
            return $display . ": " . TF::YELLOW . "x" . $value;
        } elseif (is_int($value)) {
            return $display . ": " . TF::YELLOW . "+" . $value;
        }

        return $display . ": " . TF::YELLOW . (string) $value;
    }

    // ─── Public API ──────────────────────────────────────────────────────

    /**
     * Get the skill level for a player.
     *
     * @param string $playerName The player's name
     * @param string $skill The skill name (combat, mining, survival, stealth)
     * @return int The skill level (1-50)
     */
    public function getSkillLevel(string $playerName, string $skill): int {
        $validSkills = ["combat", "mining", "survival", "stealth"];
        if (!in_array($skill, $validSkills, true)) {
            return 1;
        }

        $data = $this->getPlayerData($playerName);
        return (int) ($data[$skill]["level"] ?? 1);
    }

    /**
     * Get a specific perk value for a player's skill.
     * Returns the perk value if the player has unlocked it, or null otherwise.
     *
     * @param string $playerName The player's name
     * @param string $skill The skill name (combat, mining, survival, stealth)
     * @param string $perkName The perk name to look up
     * @return mixed The perk value, or null if not unlocked
     */
    public function getSkillPerk(string $playerName, string $skill, string $perkName): mixed {
        $validSkills = ["combat", "mining", "survival", "stealth"];
        if (!in_array($skill, $validSkills, true)) {
            return null;
        }

        $data = $this->getPlayerData($playerName);
        $level = (int) ($data[$skill]["level"] ?? 1);

        $activePerks = $this->getActivePerks($skill, $level);
        return $activePerks[$perkName] ?? null;
    }
}
