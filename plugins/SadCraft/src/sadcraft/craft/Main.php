<?php

declare(strict_types=1);

namespace sadcraft\craft;

use pocketmine\crafting\ExactRecipeIngredient;
use pocketmine\crafting\ShapelessRecipe;
use pocketmine\event\inventory\CraftItemEvent;
use pocketmine\event\Listener;
use pocketmine\item\Item;
use pocketmine\item\StringToItemParser;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\TextFormat as TF;

class Main extends PluginBase implements Listener
{
        /** @var array<string, array{name: string, lore: string, item: string, custom_name: string}> Custom items config */
        private array $customItems = [];

        /** @var array<string, array{result: string, result_name: string, result_lore: string, ingredients: string[], description: string}> Recipes config */
        private array $recipes = [];

        /** @var array<string, string> Maps "itemId:meta" of result → recipe key */
        private array $resultToRecipe = [];

        public function onEnable(): void
        {
                $this->saveDefaultConfig();
                $this->customItems = $this->getConfig()->get("custom_items", []);
                $this->recipes = $this->getConfig()->get("recipes", []);

                // Виртуальный крафт через /recipes craft — без регистрации ShapelessRecipe
                // (PM5 API изменился, регистрация через CraftingManager требует UUID и нового формата)
                $this->getServer()->getPluginManager()->registerEvents($this, $this);

                $this->getLogger()->info(TF::GREEN . "SadCraft v1.0.0 enabled! " . count($this->recipes) . " recipes, " . count($this->customItems) . " custom items.");
        }

        public function onDisable(): void
        {
                $this->getLogger()->info(TF::RED . "SadCraft disabled!");
        }

        // ─────────────────────────────────────────────────────────────────────────────
        //  Crafting recipe registration
        // ─────────────────────────────────────────────────────────────────────────────

        /**
         * Register shapeless crafting recipes using vanilla base items.
         * Custom-named ingredients are resolved to their vanilla base type so the recipe
         * appears in the recipe book.  Actual custom-name validation happens in
         * onCraftItem() at craft time.
         */
        private function registerCraftingRecipes(): void
        {
                $parser = StringToItemParser::getInstance();
                $craftingManager = $this->getServer()->getCraftingManager();

                foreach ($this->recipes as $recipeKey => $recipeData) {
                        $resultItem = $this->parseResultItem($recipeData["result"]);
                        if ($resultItem === null) {
                                $this->getLogger()->warning("Skipping recipe '$recipeKey': failed to parse result '{$recipeData["result"]}'");
                                continue;
                        }

                        $ingredients = [];
                        $valid = true;

                        foreach ($recipeData["ingredients"] as $ingredientStr) {
                                $parsed = $this->parseIngredientEntry($ingredientStr);
                                if ($parsed === null) {
                                        $this->getLogger()->warning("Skipping recipe '$recipeKey': failed to parse ingredient '$ingredientStr'");
                                        $valid = false;
                                        break;
                                }
                                [$baseItem, $count] = $parsed;
                                for ($i = 0; $i < $count; $i++) {
                                        $ingredients[] = new ExactRecipeIngredient(clone $baseItem);
                                }
                        }

                        if (!$valid || count($ingredients) === 0) {
                                continue;
                        }

                        // ShapelessRecipe в PM5 требует UUID — используем виртуальный крафт через /recipes craft
                        // $recipe = new ShapelessRecipe([$resultItem], $ingredients, \pocketmine\utils\UUID::fromRandom());
                        // $craftingManager->registerShapelessRecipe($recipe);

                        // Build a lookup so we can quickly find the recipe key from the output item
                        $resultLookup = $resultItem->getTypeId() . ":" . $resultItem->getMeta();
                        // There may be several recipes with the same base result type; store them all
                        if (!isset($this->resultToRecipe[$resultLookup])) {
                                $this->resultToRecipe[$resultLookup] = [];
                        }
                        $this->resultToRecipe[$resultLookup][] = $recipeKey;

                        $this->getLogger()->info("Registered shapeless recipe: $recipeKey (" . count($ingredients) . " ingredient slots)");
                }
        }

        // ─────────────────────────────────────────────────────────────────────────────
        //  CraftItemEvent handler — validate custom ingredient names
        // ─────────────────────────────────────────────────────────────────────────────

        /**
         * Intercept crafting events for custom recipes.
         *
         * When the output matches a registered custom recipe we check whether the input
         * items actually carry the required custom names.  If they do, we cancel the
         * vanilla craft and give the custom result instead.  If they don't, we also
         * cancel — the recipe pattern only makes sense with the proper custom items.
         */
        public function onCraftItem(CraftItemEvent $event): void
        {
                $outputs = $event->getOutputs();
                if (count($outputs) === 0) {
                        return;
                }

                $output = $outputs[0];
                $lookupKey = $output->getTypeId() . ":" . $output->getMeta();

                if (!isset($this->resultToRecipe[$lookupKey])) {
                        return;
                }

                // One base type might map to several recipes — find the best match
                $inputs = $event->getInputs();
                $matchedRecipeKey = null;

                foreach ($this->resultToRecipe[$lookupKey] as $recipeKey) {
                        if ($this->validateCustomIngredients($inputs, $this->recipes[$recipeKey]["ingredients"])) {
                                $matchedRecipeKey = $recipeKey;
                                break;
                        }
                }

                $player = $event->getPlayer();

                if ($matchedRecipeKey !== null) {
                        // ── Valid craft with custom ingredients ────────────────────────────
                        $event->cancel();
                        $recipeData = $this->recipes[$matchedRecipeKey];

                        $this->removeIngredientsFromPlayer($player, $recipeData["ingredients"]);

                        $customResult = $this->createCustomResultItem($matchedRecipeKey);
                        if ($customResult !== null) {
                                $player->getInventory()->addItem($customResult);
                                $player->sendMessage(TF::GREEN . "✔ Скрафчено: " . $recipeData["result_name"]);
                        }
                } else {
                        // ── Wrong (vanilla) ingredients ────────────────────────────────────
                        $event->cancel();
                        $player->sendMessage(TF::RED . "✖ Нужны специальные компоненты! См. /recipes");
                }
        }

        // ─────────────────────────────────────────────────────────────────────────────
        //  Command handler
        // ─────────────────────────────────────────────────────────────────────────────

        public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
        {
                return match ($command->getName()) {
                        "recipes" => $this->handleRecipesCommand($sender, $args),
                        "givemagic" => $this->handleGiveMagicCommand($sender, $args),
                        default => false,
                };
        }

        /**
         * /recipes [page]   — paginated recipe list
         * /recipes craft <name> — virtual crafting
         */
        private function handleRecipesCommand(CommandSender $sender, array $args): bool
        {
                if (!$sender instanceof Player) {
                        $sender->sendMessage(TF::RED . "Команда только для игроков!");
                        return true;
                }

                // ── Virtual craft subcommand ────────────────────────────────────────────
                if (isset($args[0]) && strtolower($args[0]) === "craft") {
                        if (!isset($args[1])) {
                                $sender->sendMessage(TF::RED . "Использование: /recipes craft <название>");
                                $sender->sendMessage(TF::YELLOW . "Доступные: " . implode(", ", array_keys($this->recipes)));
                                return true;
                        }
                        $this->craftVirtualRecipe($sender, strtolower($args[1]));
                        return true;
                }

                // ── Paginated list ──────────────────────────────────────────────────────
                $page = isset($args[0]) ? max(1, (int) $args[0]) : 1;
                $perPage = 3;
                $keys = array_keys($this->recipes);
                $total = count($keys);
                $totalPages = max(1, (int) ceil($total / $perPage));

                if ($page > $totalPages) {
                        $page = $totalPages;
                }

                $sender->sendMessage(TF::GOLD . "═══ " . TF::YELLOW . "SadCraft Рецепты " . TF::GOLD . $page . "/" . $totalPages . TF::GOLD . " ═══");

                $start = ($page - 1) * $perPage;
                $end = min($start + $perPage, $total);

                for ($i = $start; $i < $end; $i++) {
                        $key = $keys[$i];
                        $recipe = $this->recipes[$key];

                        $sender->sendMessage(TF::YELLOW . "▸ " . $recipe["result_name"]);
                        $sender->sendMessage(TF::WHITE . "  " . $recipe["description"]);
                        $sender->sendMessage(TF::GRAY . "  Ингредиенты:");

                        foreach ($recipe["ingredients"] as $ingredientStr) {
                                $sender->sendMessage(TF::GRAY . "    • " . $this->getIngredientDisplayName($ingredientStr));
                        }

                        $sender->sendMessage(TF::AQUA . "  Крафт: /recipes craft " . $key);
                        $sender->sendMessage("");
                }

                if ($page < $totalPages) {
                        $sender->sendMessage(TF::YELLOW . "→ /recipes " . ($page + 1) . " — следующая страница");
                }

                return true;
        }

        /**
         * /givemagic <item> [player] [count]
         */
        private function handleGiveMagicCommand(CommandSender $sender, array $args): bool
        {
                if (count($args) < 1) {
                        $sender->sendMessage(TF::RED . "Использование: /givemagic <item> [player] [count]");
                        $sender->sendMessage(TF::YELLOW . "Предметы: " . implode(", ", array_keys($this->customItems)));
                        return true;
                }

                $itemName = strtolower($args[0]);
                if (!isset($this->customItems[$itemName])) {
                        $sender->sendMessage(TF::RED . "Предмет '$itemName' не найден!");
                        $sender->sendMessage(TF::YELLOW . "Доступные: " . implode(", ", array_keys($this->customItems)));
                        return true;
                }

                // Resolve target player
                $target = null;
                if (isset($args[1])) {
                        $target = $this->getServer()->getPlayerByPrefix($args[1]);
                        if ($target === null) {
                                $sender->sendMessage(TF::RED . "Игрок '{$args[1]}' не найден!");
                                return true;
                        }
                } elseif ($sender instanceof Player) {
                        $target = $sender;
                } else {
                        $sender->sendMessage(TF::RED . "Укажите имя игрока!");
                        return true;
                }

                $count = isset($args[2]) ? max(1, (int) $args[2]) : 1;

                if ($this->giveCustomItem($target, $itemName, $count)) {
                        $customData = $this->customItems[$itemName];
                        $sender->sendMessage(TF::GREEN . "✔ Выдано: " . $customData["custom_name"] . TF::GREEN . " x$count → " . $target->getName());
                        if ($target !== $sender) {
                                $target->sendMessage(TF::GREEN . "✔ Вы получили: " . $customData["custom_name"] . TF::GREEN . " x$count");
                        }
                } else {
                        $sender->sendMessage(TF::RED . "✖ Не удалось выдать предмет!");
                }

                return true;
        }

        // ─────────────────────────────────────────────────────────────────────────────
        //  Public API — isCustomItem / giveCustomItem
        // ─────────────────────────────────────────────────────────────────────────────

        /**
         * Check whether an Item is a SadCraft custom item (by custom name).
         *
         * Matches against both base custom items (scrap_coin, shadow_dust, …) and
         * recipe results (Теневая броня, Кровавый клинок, …).
         */
        public function isCustomItem(Item $item): bool
        {
                $customName = $item->getCustomName();
                if ($customName === "") {
                        return false;
                }

                foreach ($this->customItems as $data) {
                        if (($data["custom_name"] ?? "") === $customName) {
                                return true;
                        }
                }

                foreach ($this->recipes as $data) {
                        if (($data["result_name"] ?? "") === $customName) {
                                return true;
                        }
                }

                return false;
        }

        /**
         * Give a custom base item to a player.
         *
         * @return bool true on success
         */
        public function giveCustomItem(Player $player, string $itemName, int $count = 1): bool
        {
                if (!isset($this->customItems[$itemName])) {
                        return false;
                }

                $data = $this->customItems[$itemName];
                $baseItem = StringToItemParser::getInstance()->parse($data["item"]);
                if ($baseItem === null) {
                        return false;
                }

                $baseItem->setCount($count);
                $baseItem->setCustomName($data["custom_name"] ?? $data["name"] ?? "");
                $baseItem->setLore([$data["lore"] ?? ""]);

                $player->getInventory()->addItem($baseItem);
                return true;
        }

        // ─────────────────────────────────────────────────────────────────────────────
        //  Virtual crafting (via /recipes craft <name>)
        // ─────────────────────────────────────────────────────────────────────────────

        /**
         * Attempt to virtually craft a recipe for the given player.
         */
        private function craftVirtualRecipe(Player $player, string $recipeKey): void
        {
                if (!isset($this->recipes[$recipeKey])) {
                        $player->sendMessage(TF::RED . "Рецепт '$recipeKey' не найден!");
                        $player->sendMessage(TF::YELLOW . "Доступные: " . implode(", ", array_keys($this->recipes)));
                        return;
                }

                $recipeData = $this->recipes[$recipeKey];

                if (!$this->playerHasIngredients($player, $recipeData["ingredients"])) {
                        $player->sendMessage(TF::RED . "✖ Недостаточно ингредиентов!");
                        $player->sendMessage(TF::GRAY . "Необходимо:");
                        foreach ($recipeData["ingredients"] as $ingredientStr) {
                                $player->sendMessage(TF::GRAY . "  • " . $this->getIngredientDisplayName($ingredientStr));
                        }
                        return;
                }

                $this->removeIngredientsFromPlayer($player, $recipeData["ingredients"]);

                $customResult = $this->createCustomResultItem($recipeKey);
                if ($customResult !== null) {
                        $player->getInventory()->addItem($customResult);
                        $player->sendMessage(TF::GREEN . "✔ Скрафчено: " . $recipeData["result_name"]);
                }
        }

        // ─────────────────────────────────────────────────────────────────────────────
        //  Ingredient / result parsing helpers
        // ─────────────────────────────────────────────────────────────────────────────

        /**
         * Parse a result string like "leather_chestplate:0:1" → Item.
         */
        private function parseResultItem(string $str): ?Item
        {
                $parts = explode(":", $str);
                $itemName = $parts[0];
                $count = (int) ($parts[2] ?? 1);

                $item = StringToItemParser::getInstance()->parse($itemName);
                if ($item === null) {
                        return null;
                }
                $item->setCount($count);
                return $item;
        }

        /**
         * Parse an ingredient string like "shadow_dust:0:8".
         * Custom item names are resolved to their vanilla base item.
         *
         * @return array{0: Item, 1: int}|null  [baseItem, requiredCount]
         */
        private function parseIngredientEntry(string $str): ?array
        {
                $parts = explode(":", $str);
                $itemName = $parts[0];
                $count = (int) ($parts[2] ?? 1);

                if (isset($this->customItems[$itemName])) {
                        $baseName = $this->customItems[$itemName]["item"];
                } else {
                        $baseName = $itemName;
                }

                $baseItem = StringToItemParser::getInstance()->parse($baseName);
                if ($baseItem === null) {
                        return null;
                }

                $baseItem->setCount(1);
                return [clone $baseItem, $count];
        }

        /**
         * Build the custom result Item for a recipe (with custom name & lore).
         */
        private function createCustomResultItem(string $recipeKey): ?Item
        {
                if (!isset($this->recipes[$recipeKey])) {
                        return null;
                }

                $data = $this->recipes[$recipeKey];
                $result = $this->parseResultItem($data["result"]);
                if ($result === null) {
                        return null;
                }

                $result->setCustomName($data["result_name"] ?? "");

                $loreRaw = $data["result_lore"] ?? "";
                $loreParts = array_map(trim(...), explode("|", $loreRaw));
                $result->setLore($loreParts);

                return $result;
        }

        /**
         * Human-readable display name for an ingredient entry.
         */
        private function getIngredientDisplayName(string $ingredientStr): string
        {
                $parts = explode(":", $ingredientStr);
                $itemName = $parts[0];
                $count = (int) ($parts[2] ?? 1);

                if (isset($this->customItems[$itemName])) {
                        return $this->customItems[$itemName]["custom_name"] . TF::WHITE . " x" . $count;
                }

                $baseItem = StringToItemParser::getInstance()->parse($itemName);
                $vanillaName = $baseItem !== null ? $baseItem->getName() : $itemName;
                return $vanillaName . TF::WHITE . " x" . $count;
        }

        // ─────────────────────────────────────────────────────────────────────────────
        //  Ingredient validation & inventory manipulation
        // ─────────────────────────────────────────────────────────────────────────────

        /**
         * Validate that a set of input items (from CraftItemEvent) contains the
         * required custom-named ingredients.
         */
        private function validateCustomIngredients(array $inputs, array $requiredIngredients): bool
        {
                // Build a map of required custom items: customName → count
                $required = [];
                foreach ($requiredIngredients as $ingredientStr) {
                        $parts = explode(":", $ingredientStr);
                        $itemName = $parts[0];
                        $count = (int) ($parts[2] ?? 1);

                        if (isset($this->customItems[$itemName])) {
                                $customName = $this->customItems[$itemName]["custom_name"];
                                $required[$customName] = ($required[$customName] ?? 0) + $count;
                        }
                }

                // If there are no custom-ingredient requirements the vanilla match is enough
                if (empty($required)) {
                        return true;
                }

                // Count what the player actually put in
                $found = [];
                foreach ($inputs as $inputItem) {
                        $cn = $inputItem->getCustomName();
                        if ($cn !== "" && isset($required[$cn])) {
                                $found[$cn] = ($found[$cn] ?? 0) + $inputItem->getCount();
                        }
                }

                foreach ($required as $customName => $needed) {
                        if (($found[$customName] ?? 0) < $needed) {
                                return false;
                        }
                }

                return true;
        }

        /**
         * Check whether a player's inventory holds all required ingredients.
         */
        private function playerHasIngredients(Player $player, array $ingredients): bool
        {
                foreach ($ingredients as $ingredientStr) {
                        $parts = explode(":", $ingredientStr);
                        $itemName = $parts[0];
                        $count = (int) ($parts[2] ?? 1);

                        if (isset($this->customItems[$itemName])) {
                                $baseItemName = $this->customItems[$itemName]["item"];
                                $customName = $this->customItems[$itemName]["custom_name"];
                                $baseItem = StringToItemParser::getInstance()->parse($baseItemName);
                        } else {
                                $customName = "";
                                $baseItem = StringToItemParser::getInstance()->parse($itemName);
                        }

                        if ($baseItem === null) {
                                return false;
                        }

                        $available = 0;
                        foreach ($player->getInventory()->getContents() as $slotItem) {
                                if ($slotItem->getTypeId() === $baseItem->getTypeId() && $slotItem->getMeta() === $baseItem->getMeta()) {
                                        if ($customName !== "" && $slotItem->getCustomName() !== $customName) {
                                                continue;
                                        }
                                        $available += $slotItem->getCount();
                                }
                        }

                        if ($available < $count) {
                                return false;
                        }
                }

                return true;
        }

        /**
         * Remove required ingredients from a player's inventory.
         * Custom-named items are matched by their custom name; vanilla items by type.
         */
        private function removeIngredientsFromPlayer(Player $player, array $ingredients): void
        {
                $inventory = $player->getInventory();

                foreach ($ingredients as $ingredientStr) {
                        $parts = explode(":", $ingredientStr);
                        $itemName = $parts[0];
                        $remaining = (int) ($parts[2] ?? 1);

                        if (isset($this->customItems[$itemName])) {
                                $baseItemName = $this->customItems[$itemName]["item"];
                                $customName = $this->customItems[$itemName]["custom_name"];
                        } else {
                                $baseItemName = $itemName;
                                $customName = "";
                        }

                        $baseItem = StringToItemParser::getInstance()->parse($baseItemName);
                        if ($baseItem === null) {
                                continue;
                        }

                        $targetId = $baseItem->getTypeId();
                        $targetMeta = $baseItem->getMeta();

                        foreach ($inventory->getContents(true) as $slot => $slotItem) {
                                if ($remaining <= 0) {
                                        break;
                                }

                                if ($slotItem->getTypeId() !== $targetId || $slotItem->getMeta() !== $targetMeta) {
                                        continue;
                                }

                                if ($customName !== "" && $slotItem->getCustomName() !== $customName) {
                                        continue;
                                }

                                $take = min($slotItem->getCount(), $remaining);
                                $newCount = $slotItem->getCount() - $take;

                                if ($newCount <= 0) {
                                        $inventory->clear($slot);
                                } else {
                                        $slotItem->setCount($newCount);
                                        $inventory->setItem($slot, $slotItem);
                                }

                                $remaining -= $take;
                        }
                }
        }
}
