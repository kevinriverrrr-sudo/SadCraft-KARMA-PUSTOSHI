<?php

declare(strict_types=1);

namespace sadcraft\core;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\entity\EntityDeathEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\Config;
use pocketmine\Server;
use pocketmine\world\Position;

class Main extends PluginBase implements Listener {

    private static Main $instance;
    private Config $karmaConfig;
    private Config $zonesConfig;
    private array $tiers = [];
    private int $broadcastInterval;

    public static function getInstance(): Main {
        return self::$instance;
    }

    public function onLoad(): void {
        self::$instance = $this;
    }

    public function onEnable(): void {
        $this->saveResource("config.yml");
        $cfg = $this->getConfig();

        $this->karmaConfig = new Config($this->getDataFolder() . "karma.json", Config::JSON);
        $this->zonesConfig = new Config($this->getDataFolder() . "zones.json", Config::JSON);

        foreach ($cfg->get("tiers", []) as $id => $tier) {
            $this->tiers[$id] = $tier;
        }

        $this->broadcastInterval = $cfg->getNested("loot_zones.broadcast_interval", 300);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        $this->getScheduler()->scheduleRepeatingTask(
            new BroadcastTask($this),
            $this->broadcastInterval * 20
        );

        $this->generateZones();

        $this->getLogger()->info("§c§lSADCRAFT §7— §eКАРМА ПУСТОШИ §aзапущен!");
    }

    public function onDisable(): void {
        $this->karmaConfig->save();
        $this->zonesConfig->save();
    }

    public function getTiers(): array {
        return $this->tiers;
    }

    public function getKarmaConfig(): Config {
        return $this->karmaConfig;
    }

    public function getZonesConfig(): Config {
        return $this->zonesConfig;
    }

    public function getKarma(string $playerName): int {
        return $this->karmaConfig->get(strtolower($playerName), $this->getConfig()->getNested("karma.start", 0));
    }

    public function setKarma(string $playerName, int $karma): void {
        $this->karmaConfig->set(strtolower($playerName), $karma);
        $this->karmaConfig->save();
    }

    public function addKarma(string $playerName, int $amount): void {
        $current = $this->getKarma($playerName);
        $this->setKarma($playerName, $current + $amount);
    }

    public function getKarmaTitle(int $karma): string {
        $villainThreshold = $this->getConfig()->getNested("karma.villain_threshold", -50);
        $heroThreshold = $this->getConfig()->getNested("karma.hero_threshold", 50);

        if ($karma <= $villainThreshold) {
            return "§cЗлодей";
        } elseif ($karma >= $heroThreshold) {
            return "§aГерой";
        } elseif ($karma < 0) {
            return "§7Отверженный";
        } else {
            return "§eСтранник";
        }
    }

    public function getLootMultiplier(string $playerName): float {
        $karma = $this->getKarma($playerName);
        $villainThreshold = $this->getConfig()->getNested("karma.villain_threshold", -50);
        $heroThreshold = $this->getConfig()->getNested("karma.hero_threshold", 50);
        $heroMultiplier = $this->getConfig()->getNested("karma.hero_loot_multiplier", 1.5);
        $villainMultiplier = $this->getConfig()->getNested("karma.villain_loot_multiplier", 0.7);

        if ($karma >= $heroThreshold) {
            return $heroMultiplier;
        } elseif ($karma <= $villainThreshold) {
            return $villainMultiplier;
        }
        return 1.0;
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $name = strtolower($player->getName());

        if (!$this->karmaConfig->exists($name)) {
            $this->setKarma($player->getName(), $this->getConfig()->getNested("karma.start", 0));
        }

        if (!$player->hasPlayedBefore()) {
            $this->randomSpawn($player);
            $player->sendMessage("§c§lSADCRAFT §7— §eКАРМА ПУСТОШИ");
            $player->sendMessage("§7Добро пожаловать в пустошь. Здесь нет спавна.");
            $player->sendMessage("§7Убивай NPC — получай лут. Убивай игроков — теряй карму.");
            $player->sendMessage("§7Команды: §e/coords§7, §e/karma§7, §e/balance§7, §e/warp§7, §e/kit§7, §e/claim");
        }

        $karma = $this->getKarma($player->getName());
        $title = $this->getKarmaTitle($karma);
        $player->sendMessage("§7Твоя карма: {$title} §7({$karma})");
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void {
        $player = $event->getPlayer();
        $cause = $player->getLastDamageCause();

        if ($cause instanceof EntityDamageByEntityEvent) {
            $damager = $cause->getDamager();
            if ($damager instanceof Player) {
                $penalty = $this->getConfig()->getNested("karma.player_kill", -15);
                $this->addKarma($damager->getName(), $penalty);
                $damager->sendMessage("§cКарма {$penalty} §7за убийство игрока §f" . $player->getName());
            }
        }
    }

    public function randomSpawn(Player $player): void {
        $cfg = $this->getConfig();
        $minDist = $cfg->getNested("random_spawn.min_distance", 500);
        $maxDist = $cfg->getNested("random_spawn.max_distance", 3000);

        $angle = mt_rand(0, 360) * (M_PI / 180);
        $distance = mt_rand($minDist, $maxDist);
        $x = (int)($distance * cos($angle));
        $z = (int)($distance * sin($angle));

        $world = $this->getServer()->getWorldManager()->getDefaultWorld();
        if ($world === null) {
            return;
        }

        $y = $world->getHighestBlockAt($x, $z) + 1;
        $pos = new Position($x, $y, $z, $world);
        $player->teleport($pos);
        $player->sendMessage("§7Ты заброшен в пустошь на координаты §bX:{$x} Z:{$z}");
    }

    public function generateZones(): void {
        if ($this->zonesConfig->get("generated", false)) {
            return;
        }

        $zones = [];
        $world = $this->getServer()->getWorldManager()->getDefaultWorld();
        if ($world === null) {
            $this->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(function(): void {
                $this->generateZones();
            }), 100);
            return;
        }

        for ($tier = 1; $tier <= 5; $tier++) {
            $count = 5 + ($tier * 2);
            $minDist = 200 + ($tier * 200);
            $maxDist = $minDist + 1500;

            for ($i = 0; $i < $count; $i++) {
                $angle = mt_rand(0, 360) * (M_PI / 180);
                $distance = mt_rand($minDist, $maxDist);
                $x = (int)($distance * cos($angle));
                $z = (int)($distance * sin($angle));

                $zones[] = [
                    "tier" => $tier,
                    "x" => $x,
                    "z" => $z,
                    "active" => true,
                    "last_loot" => 0,
                ];
            }
        }

        $this->zonesConfig->set("zones", $zones);
        $this->zonesConfig->set("generated", true);
        $this->zonesConfig->save();

        $this->getLogger()->info("Сгенерировано " . count($zones) . " лут-зон");
    }

    public function broadcastZones(): void {
        $zones = $this->zonesConfig->get("zones", []);
        if (empty($zones)) {
            return;
        }

        $activeZones = array_filter($zones, fn($z) => $z["active"] ?? true);
        if (empty($activeZones)) {
            return;
        }

        $count = min($this->getConfig()->getNested("loot_zones.broadcast_count", 3), count($activeZones));
        $selected = array_rand($activeZones, $count);
        if (!is_array($selected)) {
            $selected = [$selected];
        }

        $server = $this->getServer();
        foreach ($selected as $idx) {
            $zone = $activeZones[$idx];
            $tier = $zone["tier"];
            $tierData = $this->tiers[$tier] ?? null;
            if ($tierData === null) {
                continue;
            }

            $name = $tierData["name"] ?? "Unknown";
            $hp = $tierData["npc_health"] ?? 20;
            $msg = "§c§l[ЛОУТ] §r§7Тир §e{$tier} §7— §f{$name} §7| §bX:{$zone['x']} Z:{$zone['z']} §7| §cHP:{$hp}";

            foreach ($server->getOnlinePlayers() as $player) {
                $player->sendMessage($msg);
            }
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage("Только для игроков!");
            return true;
        }

        switch ($command->getName()) {
            case "sadcraft":
                return $this->handleSadcraft($sender, $args);
            case "coords":
                return $this->handleCoords($sender);
            case "karma":
                return $this->handleKarma($sender, $args);
            case "randomtp":
                $this->randomSpawn($sender);
                return true;
        }
        return false;
    }

    private function handleSadcraft(Player $player, array $args): bool {
        if (empty($args)) {
            $player->sendMessage("§c§lSADCRAFT §7— §eКАРМА ПУСТОШИ");
            $player->sendMessage("§7Жанр: §eRogue-Anarchy");
            $player->sendMessage("§7/sadcraft info §7— информация");
            $player->sendMessage("§7/sadcraft reload §7— перезагрузка конфига");
            $player->sendMessage("§7/sadcraft coords §7— рассылка координат");
            return true;
        }

        switch ($args[0]) {
            case "info":
                $player->sendMessage("§c§lКАРМА ПУСТОШИ");
                $player->sendMessage("§7Нет спавна. Нет правил. Только лут и карма.");
                $player->sendMessage("§7Убивай NPC разной сложности — получай лут по тирам.");
                $player->sendMessage("§7Тир 1 §7— Бродяга (лёгкий) | §eТир 5 §7— ВЛАДЫКА (нужна незерита)");
                $player->sendMessage("§7Карма влияет на множитель лута:");
                $player->sendMessage("§aГерой §7= x1.5 лут | §cЗлодей §7= x0.7 лут");
                return true;
            case "reload":
                $this->reloadConfig();
                $this->tiers = [];
                foreach ($this->getConfig()->get("tiers", []) as $id => $tier) {
                    $this->tiers[$id] = $tier;
                }
                $player->sendMessage("§aКонфиг перезагружен!");
                return true;
            case "coords":
                $this->broadcastZones();
                $player->sendMessage("§7Рассылка координат запущена!");
                return true;
        }
        return true;
    }

    private function handleCoords(Player $player): bool {
        $zones = $this->zonesConfig->get("zones", []);
        $playerZ = (int)$player->getPosition()->getZ();
        $playerX = (int)$player->getPosition()->getX();

        $player->sendMessage("§c§l[ЛОУТ] §r§7Ближайшие лут-зоны:");

        usort($zones, function($a, $b) use ($playerX, $playerZ) {
            $distA = sqrt(($a["x"] - $playerX) ** 2 + ($a["z"] - $playerZ) ** 2);
            $distB = sqrt(($b["x"] - $playerX) ** 2 + ($b["z"] - $playerZ) ** 2);
            return $distA <=> $distB;
        });

        $count = 0;
        foreach ($zones as $zone) {
            if ($count >= 5) break;
            $tier = $zone["tier"];
            $tierData = $this->tiers[$tier] ?? null;
            if ($tierData === null) continue;

            $dist = (int)sqrt(($zone["x"] - $playerX) ** 2 + ($zone["z"] - $playerZ) ** 2);
            $name = $tierData["name"] ?? "Unknown";
            $player->sendMessage("§7Тир §e{$tier} §7— §f{$name} §7| §bX:{$zone['x']} Z:{$zone['z']} §7| §d{$dist}м");
            $count++;
        }
        return true;
    }

    private function handleKarma(Player $player, array $args): bool {
        if (!empty($args)) {
            $target = $args[0];
            $karma = $this->getKarma($target);
            $title = $this->getKarmaTitle($karma);
            $player->sendMessage("§7Карма §f{$target}§7: {$title} §7({$karma})");
            return true;
        }

        $karma = $this->getKarma($player->getName());
        $title = $this->getKarmaTitle($karma);
        $player->sendMessage("§7Твоя карма: {$title} §7({$karma})");
        return true;
    }
}
