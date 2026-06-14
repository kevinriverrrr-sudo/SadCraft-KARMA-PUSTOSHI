<?php

declare(strict_types=1);

namespace sadcraft\clans;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {

    private Config $clansData;
    private Config $playersData;
    private array $invites = [];

    public function onEnable(): void {
        $this->saveResource("config.yml");
        $this->clansData = new Config($this->getDataFolder() . "clans.json", Config::JSON);
        $this->playersData = new Config($this->getDataFolder() . "players.json", Config::JSON);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("§aSadClans запущен!");
    }

    public function onDisable(): void {
        $this->clansData->save();
        $this->playersData->save();
    }

    private function getPlayerClan(string $playerName): ?string {
        return $this->playersData->get(strtolower($playerName), null);
    }

    private function setPlayerClan(string $playerName, ?string $clanName): void {
        if ($clanName === null) {
            $this->playersData->remove(strtolower($playerName));
        } else {
            $this->playersData->set(strtolower($playerName), $clanName);
        }
        $this->playersData->save();
    }

    private function getClanData(string $clanName): ?array {
        return $this->clansData->get(strtolower($clanName), null);
    }

    private function setClanData(string $clanName, array $data): void {
        $this->clansData->set(strtolower($clanName), $data);
        $this->clansData->save();
    }

    private function removeClanData(string $clanName): void {
        $this->clansData->remove(strtolower($clanName));
        $this->clansData->save();
    }

    public function onPlayerDeath(PlayerDeathEvent $event): void {
        $player = $event->getPlayer();
        $cause = $player->getLastDamageCause();

        if (!$cause instanceof EntityDamageByEntityEvent) return;
        $killer = $cause->getDamager();
        if (!$killer instanceof Player) return;

        $victimClan = $this->getPlayerClan($player->getName());
        $killerClan = $this->getPlayerClan($killer->getName());

        if ($victimClan === null || $killerClan === null) return;
        if ($victimClan === $killerClan) return;

        $clanData = $this->getClanData($killerClan);
        if ($clanData === null) return;

        $wars = $clanData["wars"] ?? [];
        if (in_array(strtolower($victimClan), $wars)) {
            $cfg = $this->getConfig();
            $killKarma = $cfg->getNested("clan_karma.enemy_kill", 5);
            $killReward = $cfg->getNested("war.kill_reward", 50);

            $clanData["karma"] = ($clanData["karma"] ?? 0) + $killKarma;
            $clanData["kills"] = ($clanData["kills"] ?? 0) + 1;
            $clanData["bank"] = ($clanData["bank"] ?? 0) + $killReward;
            $this->setClanData($killerClan, $clanData);

            $victimData = $this->getClanData($victimClan);
            if ($victimData !== null) {
                $deathKarma = $cfg->getNested("clan_karma.enemy_death", -3);
                $victimData["karma"] = ($victimData["karma"] ?? 0) + $deathKarma;
                $victimData["deaths"] = ($victimData["deaths"] ?? 0) + 1;
                $this->setClanData($victimClan, $victimData);
            }

            $killer->sendMessage("§c§l[ВОЙНА] §r§aУбийство врага! +{$killReward}S в банк клана");
            $player->sendMessage("§c§l[ВОЙНА] §r§cУбит вражеским кланом!");
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage("Только для игроков!");
            return true;
        }

        switch ($command->getName()) {
            case "clan":
                return $this->handleClan($sender, $args);
            case "clancreate":
                return $this->handleCreate($sender, $args);
            case "clandisband":
                return $this->handleDisband($sender);
            case "claninvite":
                return $this->handleInvite($sender, $args);
            case "clankick":
                return $this->handleKick($sender, $args);
            case "clanleave":
                return $this->handleLeave($sender);
            case "claninfo":
                return $this->handleInfo($sender, $args);
            case "clanlist":
                return $this->handleList($sender);
            case "clanwar":
                return $this->handleWar($sender, $args);
            case "clanally":
                return $this->handleAlly($sender, $args);
            case "clanbank":
                return $this->handleBank($sender, $args);
        }
        return false;
    }

    private function handleClan(Player $player, array $args): bool {
        if (empty($args)) {
            $clan = $this->getPlayerClan($player->getName());
            if ($clan === null) {
                $player->sendMessage("§7Ты не в клане. /clancreate <name> <tag>");
                return true;
            }
            $player->sendMessage("§7Твой клан: §e{$clan} §7| /claninfo {$clan}");
            return true;
        }

        $sub = strtolower($args[0]);
        switch ($sub) {
            case "create":
                return $this->handleCreate($player, array_slice($args, 1));
            case "disband":
                return $this->handleDisband($player);
            case "invite":
                return $this->handleInvite($player, array_slice($args, 1));
            case "kick":
                return $this->handleKick($player, array_slice($args, 1));
            case "leave":
                return $this->handleLeave($player);
            case "info":
                return $this->handleInfo($player, array_slice($args, 1));
            case "list":
                return $this->handleList($player);
            case "war":
                return $this->handleWar($player, array_slice($args, 1));
            case "ally":
                return $this->handleAlly($player, array_slice($args, 1));
            case "bank":
                return $this->handleBank($player, array_slice($args, 1));
            case "accept":
                return $this->handleAccept($player);
            case "deny":
                return $this->handleDeny($player);
            default:
                $player->sendMessage("§cНеизвестная подкоманда: {$sub}");
        }
        return true;
    }

    private function handleCreate(Player $player, array $args): bool {
        if (count($args) < 2) {
            $player->sendMessage("§cИспользование: /clancreate <название> <тег>");
            return true;
        }

        $clanName = $args[0];
        $tag = strtoupper($args[1]);
        $cfg = $this->getConfig();

        if (strlen($clanName) < $cfg->get("min_name_length", 3)) {
            $player->sendMessage("§cНазвание минимум {$cfg->get('min_name_length', 3)} символов!");
            return true;
        }
        if (strlen($clanName) > $cfg->get("max_name_length", 16)) {
            $player->sendMessage("§cНазвание максимум {$cfg->get('max_name_length', 16)} символов!");
            return true;
        }
        if (strlen($tag) !== $cfg->get("tag_length", 3)) {
            $player->sendMessage("§cТег должен быть из {$cfg->get('tag_length', 3)} символов!");
            return true;
        }
        if ($this->getPlayerClan($player->getName()) !== null) {
            $player->sendMessage("§cТы уже в клане! Сначала покинь: /clanleave");
            return true;
        }
        if ($this->getClanData($clanName) !== null) {
            $player->sendMessage("§cКлан с таким именем уже существует!");
            return true;
        }

        $cost = $cfg->get("create_cost", 2000);
        $economy = $this->getServer()->getPluginManager()->getPlugin("SadEconomy");
        if ($economy !== null) {
            $balance = $economy->getBalance($player->getName());
            if ($balance < $cost) {
                $player->sendMessage("§cСоздание клана стоит {$cost}S! У тебя: {$balance}S");
                return true;
            }
            $economy->subtractBalance($player->getName(), $cost);
            $taxPercent = $cfg->getNested("bank.tax_percent", 10);
            $bankStart = (int)($cost * $taxPercent / 100);
        } else {
            $bankStart = 0;
        }

        $this->setClanData($clanName, [
            "name" => $clanName,
            "tag" => $tag,
            "leader" => $player->getName(),
            "members" => [
                strtolower($player->getName()) => "leader"
            ],
            "karma" => 0,
            "kills" => 0,
            "deaths" => 0,
            "bank" => $bankStart,
            "wars" => [],
            "allies" => [],
            "created" => time(),
        ]);
        $this->setPlayerClan($player->getName(), $clanName);

        $player->sendMessage("§a§lКлан {$tag} {$clanName} создан! §aБанк: {$bankStart}S");
        foreach ($this->getServer()->getOnlinePlayers() as $online) {
            if ($online->getId() !== $player->getId()) {
                $online->sendMessage("§c§l[КЛАН] §r§f" . $player->getName() . " §7создал клан §e[{$tag}] {$clanName}");
            }
        }
        return true;
    }

    private function handleDisband(Player $player): bool {
        $clanName = $this->getPlayerClan($player->getName());
        if ($clanName === null) {
            $player->sendMessage("§cТы не в клане!");
            return true;
        }

        $clanData = $this->getClanData($clanName);
        if ($clanData === null || $clanData["leader"] !== $player->getName()) {
            $player->sendMessage("§cТолько лидер может распустить клан!");
            return true;
        }

        foreach ($clanData["members"] as $memberName => $rank) {
            $this->setPlayerClan($memberName, null);
        }

        $tag = $clanData["tag"] ?? "???";
        $this->removeClanData($clanName);

        $player->sendMessage("§aКлан §e[{$tag}] {$clanName} §aраспущен!");
        foreach ($this->getServer()->getOnlinePlayers() as $online) {
            if ($online->getId() !== $player->getId()) {
                $online->sendMessage("§c§l[КЛАН] §r§7Клан §e[{$tag}] {$clanName} §7распущен!");
            }
        }
        return true;
    }

    private function handleInvite(Player $player, array $args): bool {
        if (empty($args)) {
            $player->sendMessage("§cИспользование: /claninvite <игрок>");
            return true;
        }

        $clanName = $this->getPlayerClan($player->getName());
        if ($clanName === null) {
            $player->sendMessage("§cТы не в клане!");
            return true;
        }

        $clanData = $this->getClanData($clanName);
        if ($clanData === null) return true;

        $memberRank = $clanData["members"][strtolower($player->getName())] ?? "member";
        if (!in_array($memberRank, ["leader", "coleader", "officer"])) {
            $player->sendMessage("§cУ тебя нет прав для приглашения!");
            return true;
        }

        $cfg = $this->getConfig();
        $maxMembers = $cfg->get("max_members", 15);
        if (count($clanData["members"]) >= $maxMembers) {
            $player->sendMessage("§cМаксимум участников: {$maxMembers}!");
            return true;
        }

        $target = $this->getServer()->getPlayerByPrefix($args[0]);
        if ($target === null) {
            $player->sendMessage("§cИгрок не найден!");
            return true;
        }

        if ($this->getPlayerClan($target->getName()) !== null) {
            $player->sendMessage("§cИгрок уже в клане!");
            return true;
        }

        $this->invites[strtolower($target->getName())] = $clanName;
        $tag = $clanData["tag"] ?? "???";
        $player->sendMessage("§aПриглашение отправлено §f" . $target->getName());
        $target->sendMessage("§c§l[КЛАН] §r§f" . $player->getName() . " §7приглашает в §e[{$tag}] {$clanName}");
        $target->sendMessage("§7Принять: §e/clan accept §7| Отклонить: §e/clan deny");

        $this->getScheduler()->scheduleDelayedTask(new \pocketmine\scheduler\ClosureTask(function() use ($target): void {
            $name = strtolower($target->getName());
            if (isset($this->invites[$name])) {
                unset($this->invites[$name]);
                if ($target->isOnline()) {
                    $target->sendMessage("§7Приглашение в клан истекло.");
                }
            }
        }), 60 * 20);

        return true;
    }

    private function handleAccept(Player $player): bool {
        $name = strtolower($player->getName());
        if (!isset($this->invites[$name])) {
            $player->sendMessage("§cНет приглашений!");
            return true;
        }

        $clanName = $this->invites[$name];
        unset($this->invites[$name]);

        $clanData = $this->getClanData($clanName);
        if ($clanData === null) {
            $player->sendMessage("§cКлан больше не существует!");
            return true;
        }

        $clanData["members"][$name] = "member";
        $this->setClanData($clanName, $clanData);
        $this->setPlayerClan($player->getName(), $clanName);

        $tag = $clanData["tag"] ?? "???";
        $player->sendMessage("§aТы вступил в клан §e[{$tag}] {$clanName}§a!");

        foreach ($this->getServer()->getOnlinePlayers() as $online) {
            if ($online->getId() !== $player->getId()) {
                $memberClan = $this->getPlayerClan($online->getName());
                if ($memberClan === $clanName) {
                    $online->sendMessage("§c§l[КЛАН] §r§f" . $player->getName() . " §7вступил в клан!");
                }
            }
        }
        return true;
    }

    private function handleDeny(Player $player): bool {
        $name = strtolower($player->getName());
        if (isset($this->invites[$name])) {
            unset($this->invites[$name]);
            $player->sendMessage("§7Приглашение отклонено.");
        } else {
            $player->sendMessage("§cНет приглашений!");
        }
        return true;
    }

    private function handleKick(Player $player, array $args): bool {
        if (empty($args)) {
            $player->sendMessage("§cИспользование: /clankick <игрок>");
            return true;
        }

        $clanName = $this->getPlayerClan($player->getName());
        if ($clanName === null) {
            $player->sendMessage("§cТы не в клане!");
            return true;
        }

        $clanData = $this->getClanData($clanName);
        if ($clanData === null) return true;

        $memberRank = $clanData["members"][strtolower($player->getName())] ?? "member";
        if (!in_array($memberRank, ["leader", "coleader"])) {
            $player->sendMessage("§cУ тебя нет прав!");
            return true;
        }

        $targetName = strtolower($args[0]);
        if (!isset($clanData["members"][$targetName])) {
            $player->sendMessage("§cИгрок не в клане!");
            return true;
        }

        if ($clanData["members"][$targetName] === "leader") {
            $player->sendMessage("§cНельзя выгнать лидера!");
            return true;
        }

        unset($clanData["members"][$targetName]);
        $this->setClanData($clanName, $clanData);
        $this->setPlayerClan($targetName, null);

        $player->sendMessage("§aИгрок §f{$args[0]} §aисключён из клана!");
        $target = $this->getServer()->getPlayerByPrefix($args[0]);
        if ($target !== null) {
            $target->sendMessage("§cТы исключён из клана §e{$clanName}§c!");
        }
        return true;
    }

    private function handleLeave(Player $player): bool {
        $clanName = $this->getPlayerClan($player->getName());
        if ($clanName === null) {
            $player->sendMessage("§cТы не в клане!");
            return true;
        }

        $clanData = $this->getClanData($clanName);
        if ($clanData === null) return true;

        if ($clanData["leader"] === $player->getName()) {
            $player->sendMessage("§cЛидер не может покинуть клан! Передай лидерство или распусти: /clandisband");
            return true;
        }

        $name = strtolower($player->getName());
        unset($clanData["members"][$name]);
        $this->setClanData($clanName, $clanData);
        $this->setPlayerClan($player->getName(), null);

        $tag = $clanData["tag"] ?? "???";
        $player->sendMessage("§aТы покинул клан §e[{$tag}] {$clanName}");

        foreach ($this->getServer()->getOnlinePlayers() as $online) {
            if ($this->getPlayerClan($online->getName()) === $clanName) {
                $online->sendMessage("§c§l[КЛАН] §r§f" . $player->getName() . " §7покинул клан");
            }
        }
        return true;
    }

    private function handleInfo(Player $player, array $args): bool {
        $clanName = !empty($args) ? $args[0] : $this->getPlayerClan($player->getName());
        if ($clanName === null) {
            $player->sendMessage("§cТы не в клане! Укажи название: /claninfo <клан>");
            return true;
        }

        $clanData = $this->getClanData($clanName);
        if ($clanData === null) {
            $player->sendMessage("§cКлан не найден!");
            return true;
        }

        $tag = $clanData["tag"] ?? "???";
        $karma = $clanData["karma"] ?? 0;
        $karmaTitle = $karma >= 100 ? "§aБлагородный" : ($karma <= -100 ? "§cДеспотичный" : "§7Нейтральный");
        $wars = !empty($clanData["wars"]) ? implode(", ", $clanData["wars"]) : "нет";
        $allies = !empty($clanData["allies"]) ? implode(", ", $clanData["allies"]) : "нет";
        $memberCount = count($clanData["members"] ?? []);

        $player->sendMessage("§c§l[КЛАН] §r§e[{$tag}] {$clanData['name']}");
        $player->sendMessage("§7Лидер: §f{$clanData['leader']} §7| Участников: §f{$memberCount}");
        $player->sendMessage("§7Карма: {$karmaTitle} §7({$karma}) §7| Банк: §e" . ($clanData["bank"] ?? 0) . "S");
        $player->sendMessage("§7Убийств: §f" . ($clanData["kills"] ?? 0) . " §7| Смертей: §f" . ($clanData["deaths"] ?? 0));
        $player->sendMessage("§7Войны: §c{$wars} §7| Альянсы: §a{$allies}");
        return true;
    }

    private function handleList(Player $player): bool {
        $clans = $this->clansData->getAll();
        if (empty($clans)) {
            $player->sendMessage("§7Нет кланов на сервере.");
            return true;
        }

        $player->sendMessage("§c§l[КЛАНЫ] §r§7Список кланов:");
        foreach ($clans as $name => $data) {
            $tag = $data["tag"] ?? "???";
            $members = count($data["members"] ?? []);
            $karma = $data["karma"] ?? 0;
            $player->sendMessage("§7- §e[{$tag}] {$data['name']} §7({$members} чел., карма: {$karma})");
        }
        return true;
    }

    private function handleWar(Player $player, array $args): bool {
        if (empty($args)) {
            $player->sendMessage("§cИспользование: /clanwar <клан>");
            return true;
        }

        $clanName = $this->getPlayerClan($player->getName());
        if ($clanName === null) {
            $player->sendMessage("§cТы не в клане!");
            return true;
        }

        $clanData = $this->getClanData($clanName);
        if ($clanData === null) return true;

        if ($clanData["leader"] !== $player->getName()) {
            $player->sendMessage("§cТолько лидер может объявить войну!");
            return true;
        }

        $minMembers = $this->getConfig()->getNested("war.min_members", 3);
        if (count($clanData["members"]) < $minMembers) {
            $player->sendMessage("§cМинимум {$minMembers} участников для войны!");
            return true;
        }

        $targetClan = strtolower($args[0]);
        if ($targetClan === strtolower($clanName)) {
            $player->sendMessage("§cНельзя объявить войну самому себе!");
            return true;
        }

        $targetData = $this->getClanData($targetClan);
        if ($targetData === null) {
            $player->sendMessage("§cКлан не найден!");
            return true;
        }

        if (in_array($targetClan, $clanData["wars"] ?? [])) {
            $player->sendMessage("§cВы уже воюете с этим кланом!");
            return true;
        }

        if (in_array($targetClan, $clanData["allies"] ?? [])) {
            $player->sendMessage("§cНельзя объявить войну союзнику! Сначала разорвите альянс.");
            return true;
        }

        $clanData["wars"][] = $targetClan;
        $this->setClanData($clanName, $clanData);

        $targetData["wars"][] = strtolower($clanName);
        $this->setClanData($targetClan, $targetData);

        $tag1 = $clanData["tag"] ?? "???";
        $tag2 = $targetData["tag"] ?? "???";

        foreach ($this->getServer()->getOnlinePlayers() as $online) {
            $online->sendMessage("§c§l[ВОЙНА] §r§e[{$tag1}] {$clanName} §7объявил войну §e[{$tag2}] {$targetData['name']}§7!");
        }
        return true;
    }

    private function handleAlly(Player $player, array $args): bool {
        if (empty($args)) {
            $player->sendMessage("§cИспользование: /clanally <клан>");
            return true;
        }

        $clanName = $this->getPlayerClan($player->getName());
        if ($clanName === null) {
            $player->sendMessage("§cТы не в клане!");
            return true;
        }

        $clanData = $this->getClanData($clanName);
        if ($clanData === null) return true;

        if ($clanData["leader"] !== $player->getName()) {
            $player->sendMessage("§cТолько лидер может предложить альянс!");
            return true;
        }

        $maxAllies = $this->getConfig()->getNested("alliance.max_allies", 3);
        if (count($clanData["allies"] ?? []) >= $maxAllies) {
            $player->sendMessage("§cМаксимум альянсов: {$maxAllies}!");
            return true;
        }

        $targetClan = strtolower($args[0]);
        $targetData = $this->getClanData($targetClan);
        if ($targetData === null) {
            $player->sendMessage("§cКлан не найден!");
            return true;
        }

        if (in_array($targetClan, $clanData["wars"] ?? [])) {
            $player->sendMessage("§cНельзя заключить альянс с врагом!");
            return true;
        }

        if (in_array($targetClan, $clanData["allies"] ?? [])) {
            $player->sendMessage("§cВы уже союзники!");
            return true;
        }

        $clanData["allies"][] = $targetClan;
        $targetData["allies"][] = strtolower($clanName);
        $this->setClanData($clanName, $clanData);
        $this->setClanData($targetClan, $targetData);

        $tag1 = $clanData["tag"] ?? "???";
        $tag2 = $targetData["tag"] ?? "???";
        $player->sendMessage("§aАльянс с §e[{$tag2}] {$targetData['name']} §aзаключён!");

        foreach ($this->getServer()->getOnlinePlayers() as $online) {
            $online->sendMessage("§a§l[АЛЬЯНС] §r§e[{$tag1}] {$clanName} §7и §e[{$tag2}] {$targetData['name']} §7теперь союзники!");
        }
        return true;
    }

    private function handleBank(Player $player, array $args): bool {
        if (count($args) < 2) {
            $player->sendMessage("§cИспользование: /clanbank <deposit|withdraw> <сумма>");
            return true;
        }

        $clanName = $this->getPlayerClan($player->getName());
        if ($clanName === null) {
            $player->sendMessage("§cТы не в клане!");
            return true;
        }

        $clanData = $this->getClanData($clanName);
        if ($clanData === null) return true;

        $action = strtolower($args[0]);
        $amount = (int)$args[1];

        if ($amount <= 0) {
            $player->sendMessage("§cСумма должна быть положительной!");
            return true;
        }

        $economy = $this->getServer()->getPluginManager()->getPlugin("SadEconomy");
        if ($economy === null) {
            $player->sendMessage("§cЭкономика не загружена!");
            return true;
        }

        switch ($action) {
            case "deposit":
                if ($economy->subtractBalance($player->getName(), $amount)) {
                    $clanData["bank"] = ($clanData["bank"] ?? 0) + $amount;
                    $this->setClanData($clanName, $clanData);
                    $player->sendMessage("§aВнесено §e{$amount}S §aв банк клана. Баланс: §e{$clanData['bank']}S");
                } else {
                    $player->sendMessage("§cНедостаточно средств!");
                }
                break;
            case "withdraw":
                $memberRank = $clanData["members"][strtolower($player->getName())] ?? "member";
                if (!in_array($memberRank, ["leader", "coleader"])) {
                    $player->sendMessage("§cТолько лидер/совладелец может снимать!");
                    return true;
                }
                if (($clanData["bank"] ?? 0) < $amount) {
                    $player->sendMessage("§cВ банке недостаточно средств! Баланс: §e{$clanData['bank']}S");
                    return true;
                }
                $clanData["bank"] -= $amount;
                $this->setClanData($clanName, $clanData);
                $economy->addBalance($player->getName(), $amount);
                $player->sendMessage("§aСнято §e{$amount}S §aиз банка клана. Остаток: §e{$clanData['bank']}S");
                break;
            default:
                $player->sendMessage("§cДействие: deposit, withdraw");
        }
        return true;
    }
}
