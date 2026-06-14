<?php

declare(strict_types=1);

namespace sadcraft\economy;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\item\ItemFactory;
use pocketmine\item\Item;
use pocketmine\utils\Config;
use pocketmine\world\sound\PopSound;

class Main extends PluginBase implements Listener {

    private Config $economyData;
    private array $shopItems = [];
    private array $sellPrices = [];

    public function onEnable(): void {
        $this->saveResource("config.yml");
        $cfg = $this->getConfig();

        $this->economyData = new Config($this->getDataFolder() . "economy.json", Config::JSON);

        $shopData = $cfg->getNested("shop.categories", []);
        foreach ($shopData as $category => $data) {
            foreach ($data["items"] ?? [] as $itemStr) {
                $this->shopItems[] = $itemStr;
            }
        }

        $this->sellPrices = $cfg->get("sell_prices", []);

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getLogger()->info("§aSadEconomy запущен! Валюта: " . $cfg->getNested("currency.name", "Скрап"));
    }

    public function onDisable(): void {
        $this->economyData->save();
    }

    public function getBalance(string $playerName): int {
        return $this->economyData->get(strtolower($playerName), $this->getConfig()->getNested("currency.start_balance", 100));
    }

    public function setBalance(string $playerName, int $amount): void {
        $this->economyData->set(strtolower($playerName), max(0, $amount));
        $this->economyData->save();
    }

    public function addBalance(string $playerName, int $amount): void {
        $current = $this->getBalance($playerName);
        $this->setBalance($playerName, $current + $amount);
    }

    public function subtractBalance(string $playerName, int $amount): bool {
        $current = $this->getBalance($playerName);
        if ($current < $amount) {
            return false;
        }
        $this->setBalance($playerName, $current - $amount);
        return true;
    }

    public function getCurrencyName(): string {
        return $this->getConfig()->getNested("currency.name", "Скрап");
    }

    public function getCurrencySymbol(): string {
        return $this->getConfig()->getNested("currency.symbol", "S");
    }

    public function formatMoney(int $amount): string {
        return $this->getCurrencySymbol() . $amount;
    }

    public function getSellPrice(Item $item): int {
        $itemName = strtolower($item->getVanillaName());
        $typeName = strtolower($item->getTypeId() . ":" . $item->getMeta());

        if (isset($this->sellPrices[$typeName])) {
            return $this->sellPrices[$typeName];
        }

        $legacyName = str_replace(" ", "_", $itemName);
        return $this->sellPrices[$legacyName] ?? 0;
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if (!$sender instanceof Player && $command->getName() !== "eco" && $command->getName() !== "balance") {
            $sender->sendMessage("Только для игроков!");
            return true;
        }

        switch ($command->getName()) {
            case "balance":
                return $this->handleBalance($sender, $args);
            case "pay":
                return $this->handlePay($sender, $args);
            case "shop":
                return $this->handleShop($sender, $args);
            case "sell":
                return $this->handleSell($sender);
            case "sellall":
                return $this->handleSellAll($sender);
            case "eco":
                return $this->handleEco($sender, $args);
        }
        return false;
    }

    private function handleBalance(CommandSender $sender, array $args): bool {
        if (!empty($args)) {
            $target = $args[0];
            $balance = $this->getBalance($target);
            $sender->sendMessage("§7Баланс §f{$target}§7: §e" . $this->formatMoney($balance));
            return true;
        }

        if ($sender instanceof Player) {
            $balance = $this->getBalance($sender->getName());
            $sender->sendMessage("§7Твой баланс: §e" . $this->formatMoney($balance));
        }
        return true;
    }

    private function handlePay(Player $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage("§cИспользование: /pay <игрок> <сумма>");
            return true;
        }

        $target = $args[0];
        $amount = (int)$args[1];

        if ($amount <= 0) {
            $sender->sendMessage("§cСумма должна быть положительной!");
            return true;
        }

        $targetPlayer = $this->getServer()->getPlayerByPrefix($target);
        if ($targetPlayer === null) {
            $sender->sendMessage("§cИгрок не найден!");
            return true;
        }

        if (strtolower($sender->getName()) === strtolower($targetPlayer->getName())) {
            $sender->sendMessage("§cНельзя платить самому себе!");
            return true;
        }

        if (!$this->subtractBalance($sender->getName(), $amount)) {
            $sender->sendMessage("§cНедостаточно средств! Твой баланс: §e" . $this->formatMoney($this->getBalance($sender->getName())));
            return true;
        }

        $this->addBalance($targetPlayer->getName(), $amount);
        $sender->sendMessage("§aПереведено §e" . $this->formatMoney($amount) . " §aигроку §f" . $targetPlayer->getName());
        $targetPlayer->sendMessage("§aПолучено §e" . $this->formatMoney($amount) . " §aот §f" . $sender->getName());
        return true;
    }

    private function handleShop(Player $sender, array $args): bool {
        $page = !empty($args) ? (int)$args[0] : 1;
        $perPage = $this->getConfig()->getNested("shop.items_per_page", 9);
        $total = count($this->shopItems);
        $maxPage = (int)ceil($total / $perPage);

        if ($page < 1) $page = 1;
        if ($page > $maxPage) $page = $maxPage;

        $offset = ($page - 1) * $perPage;
        $items = array_slice($this->shopItems, $offset, $perPage);

        $sender->sendMessage("§c§l[МАГАЗИН] §r§7Страница §e{$page}/{$maxPage}");

        foreach ($items as $idx => $itemStr) {
            $parts = explode(":", $itemStr);
            if (count($parts) < 4) continue;

            $itemType = $parts[0];
            $meta = (int)$parts[1];
            $count = (int)$parts[2];
            $price = (int)$parts[3];

            $slot = $offset + $idx + 1;
            $sender->sendMessage("§7{$slot}. §f{$itemType} x{$count} §7— §e" . $this->formatMoney($price) . " §7| /buy {$slot}");
        }

        if ($page < $maxPage) {
            $sender->sendMessage("§7Следующая страница: §e/shop " . ($page + 1));
        }

        return true;
    }

    private function handleSell(Player $sender): bool {
        $item = $sender->getInventory()->getItemInHand();
        if ($item->isNull()) {
            $sender->sendMessage("§cВозьми предмет в руку!");
            return true;
        }

        $pricePer = $this->getSellPrice($item);
        if ($pricePer <= 0) {
            $sender->sendMessage("§cЭтот предмет нельзя продать!");
            return true;
        }

        $count = $item->getCount();
        $total = $pricePer * $count;

        $this->addBalance($sender->getName(), $total);
        $sender->getInventory()->setItemInHand($item->setCount(0));
        $sender->sendMessage("§aПродано §f{$count}x §7за §e" . $this->formatMoney($total));
        return true;
    }

    private function handleSellAll(CommandSender $sender): bool {
        if (!$sender instanceof Player) return true;

        $totalEarned = 0;
        $soldCount = 0;
        $inventory = $sender->getInventory();

        for ($i = 0; $i < $inventory->getSize(); $i++) {
            $item = $inventory->getItem($i);
            if ($item->isNull()) continue;

            $pricePer = $this->getSellPrice($item);
            if ($pricePer <= 0) continue;

            $count = $item->getCount();
            $total = $pricePer * $count;
            $totalEarned += $total;
            $soldCount += $count;
            $inventory->setItem($i, $item->setCount(0));
        }

        if ($totalEarned > 0) {
            $this->addBalance($sender->getName(), $totalEarned);
            $sender->sendMessage("§aПродано §f{$soldCount} §aпредметов за §e" . $this->formatMoney($totalEarned));
        } else {
            $sender->sendMessage("§cНечего продавать!");
        }
        return true;
    }

    private function handleEco(CommandSender $sender, array $args): bool {
        if (count($args) < 2) {
            $sender->sendMessage("§cИспользование: /eco <give|take|set|reset> <игрок> [сумма]");
            return true;
        }

        $action = $args[0];
        $target = $args[1];

        switch ($action) {
            case "give":
                if (count($args) < 3) {
                    $sender->sendMessage("§cУкажи сумму!");
                    return true;
                }
                $amount = (int)$args[2];
                $this->addBalance($target, $amount);
                $sender->sendMessage("§aНачислено §e" . $this->formatMoney($amount) . " §aигроку §f{$target}");
                break;
            case "take":
                if (count($args) < 3) {
                    $sender->sendMessage("§cУкажи сумму!");
                    return true;
                }
                $amount = (int)$args[2];
                $this->subtractBalance($target, $amount);
                $sender->sendMessage("§aСписано §e" . $this->formatMoney($amount) . " §aу игрока §f{$target}");
                break;
            case "set":
                if (count($args) < 3) {
                    $sender->sendMessage("§cУкажи сумму!");
                    return true;
                }
                $amount = (int)$args[2];
                $this->setBalance($target, $amount);
                $sender->sendMessage("§aБаланс §f{$target} §aустановлен: §e" . $this->formatMoney($amount));
                break;
            case "reset":
                $startBalance = $this->getConfig()->getNested("currency.start_balance", 100);
                $this->setBalance($target, $startBalance);
                $sender->sendMessage("§aБаланс §f{$target} §aсброшен: §e" . $this->formatMoney($startBalance));
                break;
            default:
                $sender->sendMessage("§cДействие: give, take, set, reset");
        }
        return true;
    }
}
