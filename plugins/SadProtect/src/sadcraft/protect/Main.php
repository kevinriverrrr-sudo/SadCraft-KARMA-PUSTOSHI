<?php

declare(strict_types=1);

namespace sadcraft\protect;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener {

    private Config $claimsData;
    private int $defaultRadius;
    private int $maxClaimsDefault;
    private int $claimCost;
    private int $maxRadius;

    public function onEnable(): void {
        $this->saveResource("config.yml");
        $cfg = $this->getConfig();

        $this->claimsData = new Config($this->getDataFolder() . "claims.json", Config::JSON);

        $this->defaultRadius = $cfg->get("default_radius", 16);
        $this->maxClaimsDefault = $cfg->get("max_claims_default", 2);
        $this->claimCost = $cfg->get("claim_cost", 200);
        $this->maxRadius = $cfg->get("max_radius", 32);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("§aSadProtect запущен!");
    }

    public function onDisable(): void {
        $this->claimsData->save();
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $claim = $this->getClaimAt($block->getPosition()->getX(), $block->getPosition()->getZ(), $block->getPosition()->getWorld()->getFolderName());

        if ($claim !== null && !$this->canInteract($player, $claim)) {
            $event->cancel();
            $player->sendMessage("§cЭта территория защищена! Владелец: §f" . $claim["owner"]);
        }
    }

    public function onBlockPlace(BlockPlaceEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $claim = $this->getClaimAt($block->getPosition()->getX(), $block->getPosition()->getZ(), $block->getPosition()->getWorld()->getFolderName());

        if ($claim !== null && !$this->canInteract($player, $claim)) {
            $event->cancel();
            $player->sendMessage("§cЭта территория защищена! Владелец: §f" . $claim["owner"]);
        }
    }

    public function onPlayerInteract(PlayerInteractEvent $event): void {
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $claim = $this->getClaimAt($block->getPosition()->getX(), $block->getPosition()->getZ(), $block->getPosition()->getWorld()->getFolderName());

        if ($claim !== null && !$this->canInteract($player, $claim)) {
            $event->cancel();
            $player->sendMessage("§cЭта территория защищена! Владелец: §f" . $claim["owner"]);
        }
    }

    private function getClaimAt(float $x, float $z, string $worldName): ?array {
        $claims = $this->claimsData->getAll();

        foreach ($claims as $name => $claim) {
            if (($claim["world"] ?? "") !== $worldName) continue;

            $cx = $claim["x"];
            $cz = $claim["z"];
            $radius = $claim["radius"] ?? $this->defaultRadius;

            if ($x >= $cx - $radius && $x <= $cx + $radius &&
                $z >= $cz - $radius && $z <= $cz + $radius) {
                return $claim;
            }
        }
        return null;
    }

    private function canInteract(Player $player, array $claim): bool {
        $owner = $claim["owner"] ?? "";
        $trusted = $claim["trusted"] ?? [];

        if (strtolower($player->getName()) === strtolower($owner)) return true;
        if (in_array(strtolower($player->getName()), $trusted)) return true;
        if ($player->hasPermission("sadcraft.protect.admin")) return true;

        return false;
    }

    private function getMaxClaims(Player $player): int {
        $max = $this->maxClaimsDefault;
        if ($player->hasPermission("sadcraft.protect.max.10")) $max = 10;
        elseif ($player->hasPermission("sadcraft.protect.max.5")) $max = 5;
        return $max;
    }

    private function getPlayerClaimCount(string $playerName): int {
        $count = 0;
        $claims = $this->claimsData->getAll();
        foreach ($claims as $claim) {
            if (strtolower($claim["owner"] ?? "") === strtolower($playerName)) {
                $count++;
            }
        }
        return $count;
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player) {
            $sender->sendMessage("Только для игроков!");
            return true;
        }

        switch ($command->getName()) {
            case "claim":
                return $this->handleClaim($sender, $args);
            case "unclaim":
                return $this->handleUnclaim($sender, $args);
            case "trust":
                return $this->handleTrust($sender, $args);
            case "untrust":
                return $this->handleUntrust($sender, $args);
            case "claims":
                return $this->handleClaims($sender);
            case "claiminfo":
                return $this->handleClaimInfo($sender);
        }
        return false;
    }

    private function handleClaim(Player $player, array $args): bool {
        $maxClaims = $this->getMaxClaims($player);
        $currentClaims = $this->getPlayerClaimCount($player->getName());

        if ($currentClaims >= $maxClaims) {
            $player->sendMessage("§cМаксимум приватов: §e{$maxClaims}§c! Удали старый: /unclaim");
            return true;
        }

        $claimName = !empty($args) ? strtolower($args[0]) : "claim_" . ($currentClaims + 1);

        if ($this->claimsData->exists($claimName)) {
            $player->sendMessage("§cПриват с именем §f{$claimName} §cуже существует!");
            return true;
        }

        $pos = $player->getPosition();
        $existingClaim = $this->getClaimAt($pos->getX(), $pos->getZ(), $pos->getWorld()->getFolderName());
        if ($existingClaim !== null) {
            $player->sendMessage("§cЭта территория уже занята! Владелец: §f" . $existingClaim["owner"]);
            return true;
        }

        $economy = $this->getServer()->getPluginManager()->getPlugin("SadEconomy");
        if ($economy !== null && $this->claimCost > 0) {
            $balance = $economy->getBalance($player->getName());
            if ($balance < $this->claimCost) {
                $player->sendMessage("§cПриват стоит §e{$this->claimCost}S§c! У тебя: §e{$balance}S");
                return true;
            }
            $economy->subtractBalance($player->getName(), $this->claimCost);
        }

        $this->claimsData->set($claimName, [
            "owner" => $player->getName(),
            "x" => (int)$pos->getX(),
            "y" => (int)$pos->getY(),
            "z" => (int)$pos->getZ(),
            "world" => $pos->getWorld()->getFolderName(),
            "radius" => $this->defaultRadius,
            "trusted" => [],
            "created" => time(),
        ]);
        $this->claimsData->save();

        $player->sendMessage("§aТерритория §e{$claimName} §aприватизирована! Радиус: §e{$this->defaultRadius} блоков");
        $player->sendMessage("§7Список приватов: §e/claims §7| Добавить друга: §e/trust {$claimName} <ник>");
        return true;
    }

    private function handleUnclaim(Player $player, array $args): bool {
        if (empty($args)) {
            $player->sendMessage("§cИспользование: /unclaim <название>");
            return true;
        }

        $claimName = strtolower($args[0]);
        $claim = $this->claimsData->get($claimName);

        if ($claim === null) {
            $player->sendMessage("§cПриват §f{$claimName} §cне найден!");
            return true;
        }

        if (strtolower($claim["owner"]) !== strtolower($player->getName()) &&
            !$player->hasPermission("sadcraft.protect.admin")) {
            $player->sendMessage("§cТы не владелец этого привата!");
            return true;
        }

        $this->claimsData->remove($claimName);
        $this->claimsData->save();
        $player->sendMessage("§aПриват §e{$claimName} §aудалён!");
        return true;
    }

    private function handleTrust(Player $player, array $args): bool {
        if (count($args) < 2) {
            $player->sendMessage("§cИспользование: /trust <приват> <игрок>");
            return true;
        }

        $claimName = strtolower($args[0]);
        $trustPlayer = strtolower($args[1]);

        $claim = $this->claimsData->get($claimName);
        if ($claim === null) {
            $player->sendMessage("§cПриват §f{$claimName} §cне найден!");
            return true;
        }

        if (strtolower($claim["owner"]) !== strtolower($player->getName())) {
            $player->sendMessage("§cТы не владелец!");
            return true;
        }

        $trusted = $claim["trusted"] ?? [];
        if (in_array($trustPlayer, $trusted)) {
            $player->sendMessage("§7Игрок §f{$trustPlayer} §7уже в привате.");
            return true;
        }

        $trusted[] = $trustPlayer;
        $claim["trusted"] = $trusted;
        $this->claimsData->set($claimName, $claim);
        $this->claimsData->save();

        $player->sendMessage("§aИгрок §e{$trustPlayer} §aдобавлен в приват §e{$claimName}§a!");
        return true;
    }

    private function handleUntrust(Player $player, array $args): bool {
        if (count($args) < 2) {
            $player->sendMessage("§cИспользование: /untrust <приват> <игрок>");
            return true;
        }

        $claimName = strtolower($args[0]);
        $untrustPlayer = strtolower($args[1]);

        $claim = $this->claimsData->get($claimName);
        if ($claim === null) {
            $player->sendMessage("§cПриват §f{$claimName} §cне найден!");
            return true;
        }

        if (strtolower($claim["owner"]) !== strtolower($player->getName())) {
            $player->sendMessage("§cТы не владелец!");
            return true;
        }

        $trusted = $claim["trusted"] ?? [];
        $key = array_search($untrustPlayer, $trusted);
        if ($key === false) {
            $player->sendMessage("§7Игрок §f{$untrustPlayer} §7не в привате.");
            return true;
        }

        unset($trusted[$key]);
        $claim["trusted"] = array_values($trusted);
        $this->claimsData->set($claimName, $claim);
        $this->claimsData->save();

        $player->sendMessage("§aИгрок §e{$untrustPlayer} §aудалён из привата §e{$claimName}§a!");
        return true;
    }

    private function handleClaims(Player $player): bool {
        $claims = $this->claimsData->getAll();
        $playerClaims = [];

        foreach ($claims as $name => $claim) {
            if (strtolower($claim["owner"] ?? "") === strtolower($player->getName())) {
                $playerClaims[$name] = $claim;
            }
        }

        if (empty($playerClaims)) {
            $player->sendMessage("§7У тебя нет приватов. /claim для создания.");
            return true;
        }

        $player->sendMessage("§c§l[ПРИВАТЫ] §r§7Твои территории:");
        foreach ($playerClaims as $name => $claim) {
            $radius = $claim["radius"] ?? $this->defaultRadius;
            $trusted = implode(", ", $claim["trusted"] ?? []);
            $player->sendMessage("§7- §e{$name} §7(X:{$claim['x']} Z:{$claim['z']} R:{$radius}) §7| Друзья: §f" . ($trusted ?: "нет"));
        }
        return true;
    }

    private function handleClaimInfo(Player $player): bool {
        $pos = $player->getPosition();
        $claim = $this->getClaimAt($pos->getX(), $pos->getZ(), $pos->getWorld()->getFolderName());

        if ($claim === null) {
            $player->sendMessage("§7Здесь нет привата. Свободная территория!");
            return true;
        }

        $owner = $claim["owner"] ?? "Unknown";
        $radius = $claim["radius"] ?? $this->defaultRadius;
        $trusted = implode(", ", $claim["trusted"] ?? []);

        $player->sendMessage("§c§l[ПРИВАТ] §r§7Информация:");
        $player->sendMessage("§7Владелец: §f{$owner}");
        $player->sendMessage("§7Центр: §bX:{$claim['x']} Z:{$claim['z']} §7Радиус: §e{$radius}");
        $player->sendMessage("§7Доверенные: §f" . ($trusted ?: "нет"));
        return true;
    }
}
