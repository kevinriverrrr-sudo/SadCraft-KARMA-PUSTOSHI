<?php

declare(strict_types=1);

namespace sadcraft\bounty;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\Config;
use sadcraft\economy\Main as EconomyMain;

class Main extends PluginBase implements Listener {

    private Config $bountiesConfig;
    private Config $huntersConfig;
    private array $cooldowns = [];

    private ?EconomyMain $economy = null;

    public function onEnable(): void {
        $this->saveDefaultConfig();

        $this->bountiesConfig = new Config($this->getDataFolder() . "bounties.json", Config::JSON);
        $this->huntersConfig = new Config($this->getDataFolder() . "hunters.json", Config::JSON);

        $economyPlugin = $this->getServer()->getPluginManager()->getPlugin("SadEconomy");
        if ($economyPlugin instanceof EconomyMain) {
            $this->economy = $economyPlugin;
        } else {
            $this->getLogger()->warning("SadEconomy не найден! Работа наград будет ограничена.");
        }

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("§aSadBounty запущен! Охота за головами активна.");
    }

    public function onDisable(): void {
        $this->bountiesConfig->save();
        $this->huntersConfig->save();
    }

    private function getEconomy(): ?EconomyMain {
        if ($this->economy === null || !$this->economy->isEnabled()) {
            $plugin = $this->getServer()->getPluginManager()->getPlugin("SadEconomy");
            if ($plugin instanceof EconomyMain && $plugin->isEnabled()) {
                $this->economy = $plugin;
            }
        }
        return $this->economy;
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        switch ($command->getName()) {
            case "bounty":
                if (!$sender instanceof Player) {
                    $sender->sendMessage("§cКоманда доступна только игрокам!");
                    return true;
                }
                return $this->handleBounty($sender, $args);

            case "bounties":
                return $this->handleBounties($sender, $args);

            case "bountytop":
                return $this->handleBountytop($sender);
        }

        return false;
    }

    private function handleBounty(Player $player, array $args): bool {
        if (count($args) < 2) {
            $player->sendMessage("§cИспользование: /bounty <player> <amount>");
            return true;
        }

        $targetName = $args[0];
        $amount = (int) $args[1];

        // Validate target is not self
        if (strtolower($targetName) === strtolower($player->getName())) {
            $player->sendMessage("§cНельзя поставить награду за себя!");
            return true;
        }

        // Check target exists (online)
        $target = $this->getServer()->getPlayerByPrefix($targetName);
        if ($target === null) {
            $player->sendMessage("§cИгрок не найден!");
            return true;
        }

        $minBounty = (int) $this->getConfig()->get("min_bounty", 50);
        $maxBounty = (int) $this->getConfig()->get("max_bounty", 50000);
        $taxPercent = (int) $this->getConfig()->get("bounty_tax", 5);
        $cooldownSeconds = (int) $this->getConfig()->get("bounty_cooldown", 300);

        // Validate amount
        if ($amount < $minBounty) {
            $player->sendMessage("§cМинимальная сумма награды: §e{$minBounty}S");
            return true;
        }

        if ($amount > $maxBounty) {
            $player->sendMessage("§cМаксимальная сумма награды: §e{$maxBounty}S");
            return true;
        }

        // Check cooldown
        $placerKey = strtolower($player->getName());
        $targetKey = strtolower($target->getName());
        $cooldownId = $placerKey . ":" . $targetKey;

        if (isset($this->cooldowns[$cooldownId])) {
            $remaining = $this->cooldowns[$cooldownId] - time();
            if ($remaining > 0) {
                $player->sendMessage("§cПодождите §e{$remaining}§c сек. перед новой наградой на этого игрока!");
                return true;
            }
            unset($this->cooldowns[$cooldownId]);
        }

        // Calculate total cost with tax
        $tax = (int) ceil($amount * $taxPercent / 100);
        $totalCost = $amount + $tax;

        // Check economy
        $economy = $this->getEconomy();
        if ($economy === null) {
            $player->sendMessage("§cЭкономика недоступна!");
            return true;
        }

        $playerBalance = $economy->getBalance($player->getName());
        if ($playerBalance < $totalCost) {
            $player->sendMessage("§cНедостаточно средств! Нужно: §e{$totalCost}S §7(награда §e{$amount}S §7+ комиссия §e{$tax}S§7)");
            return true;
        }

        // Deduct money
        $economy->subtractBalance($player->getName(), $totalCost);

        // Set cooldown
        $this->cooldowns[$cooldownId] = time() + $cooldownSeconds;

        // Record bounty
        $bounties = $this->bountiesConfig->getAll();

        if (!isset($bounties[$targetKey])) {
            $bounties[$targetKey] = [
                "name" => $target->getName(),
                "total" => 0,
                "bounties" => []
            ];
        }

        $bounties[$targetKey]["total"] += $amount;
        $bounties[$targetKey]["bounties"][] = [
            "placer" => $player->getName(),
            "amount" => $amount,
            "timestamp" => time()
        ];

        $this->bountiesConfig->setAll($bounties);
        $this->bountiesConfig->save();

        $newTotal = $bounties[$targetKey]["total"];
        $this->getServer()->broadcastMessage(
            "§c§l[НАГРАДА] §r§f" . $player->getName() . " §7поставил награду §e{$amount}S §7за голову §f" . $target->getName() . "§7! Итого: §e{$newTotal}S"
        );

        return true;
    }

    private function handleBounties(CommandSender $sender, array $args): bool {
        $bounties = $this->bountiesConfig->getAll();

        if (empty($bounties)) {
            $sender->sendMessage("§c§l[НАГРАДЫ] §r§7Нет активных наград!");
            return true;
        }

        // Sort by total descending
        uasort($bounties, function (array $a, array $b): int {
            return $b["total"] <=> $a["total"];
        });

        $perPage = 5;
        $page = isset($args[0]) ? max(1, (int) $args[0]) : 1;
        $totalPages = max(1, (int) ceil(count($bounties) / $perPage));
        $page = min($page, $totalPages);

        $sender->sendMessage("§c§l[НАГРАДЫ] §r§7Страница {$page}/{$totalPages}");

        $offset = ($page - 1) * $perPage;
        $items = array_slice($bounties, $offset, $perPage, true);

        foreach ($items as $data) {
            $name = $data["name"];
            $total = $data["total"];
            $count = count($data["bounties"]);
            $sender->sendMessage("§7- §f{$name} §7| §e{$total}S §7| §7Наград: {$count}");
        }

        if ($page < $totalPages) {
            $sender->sendMessage("§7Следующая: §e/bounties " . ($page + 1));
        }

        return true;
    }

    private function handleBountytop(CommandSender $sender): bool {
        $hunters = $this->huntersConfig->getAll();

        if (empty($hunters)) {
            $sender->sendMessage("§c§l[ОХОТНИКИ] §r§7Пока нет охотников!");
            return true;
        }

        // Sort by kills descending
        uasort($hunters, function (array $a, array $b): int {
            return $b["kills"] <=> $a["kills"];
        });

        $sender->sendMessage("§c§l[ОХОТНИКИ]");

        $rank = 1;
        foreach (array_slice($hunters, 0, 10) as $data) {
            $name = $data["name"];
            $kills = $data["kills"];
            $sender->sendMessage("§7{$rank}. §f{$name} §7— §a{$kills}");
            $rank++;
        }

        return true;
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void {
        $victim = $event->getPlayer();
        $cause = $victim->getLastDamageCause();

        if (!$cause instanceof EntityDamageByEntityEvent) {
            return;
        }

        $damager = $cause->getDamager();
        if (!$damager instanceof Player) {
            return;
        }

        // Killer and victim must be different players
        if (strtolower($damager->getName()) === strtolower($victim->getName())) {
            return;
        }

        $victimKey = strtolower($victim->getName());
        $bounties = $this->bountiesConfig->getAll();

        // Check if victim has a bounty
        if (!isset($bounties[$victimKey])) {
            return;
        }

        $bountyData = $bounties[$victimKey];
        $totalBounty = $bountyData["total"];

        if ($totalBounty <= 0) {
            return;
        }

        // Calculate reward splits
        $hunterPercent = (int) $this->getConfig()->get("hunter_reward_percent", 80);
        $victimPercent = (int) $this->getConfig()->get("victim_return_percent", 20);

        $hunterReward = (int) floor($totalBounty * $hunterPercent / 100);
        $victimReturn = (int) floor($totalBounty * $victimPercent / 100);

        // Distribute rewards via economy
        $economy = $this->getEconomy();
        if ($economy !== null) {
            $economy->addBalance($damager->getName(), $hunterReward);
            $economy->addBalance($victim->getName(), $victimReturn);
        }

        // Update hunter stats
        $hunters = $this->huntersConfig->getAll();
        $killerKey = strtolower($damager->getName());

        if (!isset($hunters[$killerKey])) {
            $hunters[$killerKey] = [
                "name" => $damager->getName(),
                "kills" => 0,
                "total_earned" => 0
            ];
        }

        $hunters[$killerKey]["kills"]++;
        $hunters[$killerKey]["total_earned"] += $hunterReward;
        $hunters[$killerKey]["name"] = $damager->getName();

        $this->huntersConfig->setAll($hunters);
        $this->huntersConfig->save();

        // Remove bounty from victim
        unset($bounties[$victimKey]);
        $this->bountiesConfig->setAll($bounties);
        $this->bountiesConfig->save();

        // Broadcast the kill
        $killerName = $damager->getName();
        $victimName = $victim->getName();
        $this->getServer()->broadcastMessage(
            "§c§l[НАГРАДА] §r§f{$killerName} §7убил разыскиваемого §f{$victimName}§7! Награда: §e{$hunterReward}S"
        );
    }
}
