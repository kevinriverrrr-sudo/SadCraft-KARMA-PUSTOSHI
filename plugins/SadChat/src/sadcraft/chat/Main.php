<?php

declare(strict_types=1);

namespace sadcraft\chat;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat as TF;

class Main extends PluginBase implements Listener {

        /** @var array<string, string> player name => active channel */
        private array $channels = [];

        /** @var array<string, int> player name => last message timestamp */
        private array $lastMessageTime = [];

        /** @var array<string, string> player name => last message content */
        private array $lastMessageContent = [];

        /** @var array<string, int> player name => identical message streak count */
        private array $spamCount = [];

        /** @var array<string, int> player name => spam mute expiry timestamp */
        private array $spamMuted = [];

        /** @var array<string, string> player name => last PM sender name */
        private array $replyTarget = [];

        private bool $chatMuted = false;

        // Config values
        private int $localChatRadius;
        private array $formats;
        private array $karmaPrefixes;
        private array $badWords;
        private string $replacement;
        private int $messageCooldown;
        private int $maxMessageLength;
        private int $spamThreshold;
        private int $spamMuteDuration;

        private const CHANNELS = ["global", "local", "trade", "help"];

        public function onEnable() : void {
                $this->saveDefaultConfig();
                $this->reloadConfig();

                $config = $this->getConfig();
                $this->localChatRadius = (int) $config->get("local_chat_radius", 50);
                $this->formats = $config->get("formats", []);
                $this->karmaPrefixes = $config->get("karma_prefixes", []);
                $this->badWords = $config->get("bad_words", []);
                $this->replacement = (string) $config->get("replacement", "***");
                $this->messageCooldown = (int) $config->get("message_cooldown", 2);
                $this->maxMessageLength = (int) $config->get("max_message_length", 200);
                $this->spamThreshold = (int) $config->get("spam_threshold", 3);
                $this->spamMuteDuration = (int) $config->get("spam_mute_duration", 30);

                $this->getServer()->getPluginManager()->registerEvents($this, $this);
                $this->getLogger()->info("SadChat enabled!");
        }

        public function onDisable() : void {
                $this->getLogger()->info("SadChat disabled!");
        }

        // ─── SadCore Karma Integration ────────────────────────────────────

        private function getKarmaPrefix(Player $player) : string {
                $sadCore = $this->getServer()->getPluginManager()->getPlugin("SadCore");
                if ($sadCore === null) {
                        return $this->karmaPrefixes["wanderer"] ?? "";
                }

                try {
                        $karma = null;
                        if (method_exists($sadCore, "getPlayerKarma")) {
                                $karma = $sadCore->getPlayerKarma($player);
                        } elseif (method_exists($sadCore, "getKarma")) {
                                $karma = $sadCore->getKarma($player->getName());
                        }

                        if ($karma === null || !is_numeric($karma)) {
                                return $this->karmaPrefixes["wanderer"] ?? "";
                        }

                        $karmaValue = (float) $karma;
                        if ($karmaValue <= -50) {
                                return $this->karmaPrefixes["villain"] ?? "";
                        } elseif ($karmaValue <= -10) {
                                return $this->karmaPrefixes["outcast"] ?? "";
                        } elseif ($karmaValue < 50) {
                                return $this->karmaPrefixes["wanderer"] ?? "";
                        } else {
                                return $this->karmaPrefixes["hero"] ?? "";
                        }
                } catch (\Throwable $e) {
                        return $this->karmaPrefixes["wanderer"] ?? "";
                }
        }

        // ─── Bad Word Filter ──────────────────────────────────────────────

        private function filterMessage(string $message) : string {
                foreach ($this->badWords as $badWord) {
                        $badWord = (string) $badWord;
                        if ($badWord === "") {
                                continue;
                        }
                        $pattern = "/" . preg_quote($badWord, "/") . "/iu";
                        $message = preg_replace($pattern, $this->replacement, $message);
                }
                return $message;
        }

        // ─── Spam / Cooldown Checks ───────────────────────────────────────

        private function isSpamMuted(Player $player) : bool {
                $name = $player->getName();
                if (!isset($this->spamMuted[$name])) {
                        return false;
                }
                if (time() >= $this->spamMuted[$name]) {
                        unset($this->spamMuted[$name]);
                        return false;
                }
                return true;
        }

        private function checkSpam(Player $player, string $message) : bool {
                $name = $player->getName();
                $now = time();

                // Cooldown check
                if (isset($this->lastMessageTime[$name])) {
                        $elapsed = $now - $this->lastMessageTime[$name];
                        if ($elapsed < $this->messageCooldown) {
                                $remaining = $this->messageCooldown - $elapsed;
                                $player->sendMessage(TF::RED . "Подожди {$remaining} сек. перед следующим сообщением.");
                                return true; // blocked
                        }
                }

                // Identical message spam check
                if (isset($this->lastMessageContent[$name]) && strtolower($this->lastMessageContent[$name]) === strtolower($message)) {
                        $this->spamCount[$name] = ($this->spamCount[$name] ?? 0) + 1;
                } else {
                        $this->spamCount[$name] = 1;
                }

                if ($this->spamCount[$name] >= $this->spamThreshold) {
                        $this->spamMuted[$name] = $now + $this->spamMuteDuration;
                        $this->spamCount[$name] = 0;
                        $player->sendMessage(TF::RED . "Вы замьючены на {$this->spamMuteDuration} сек. за спам!");
                        return true; // blocked
                }

                $this->lastMessageTime[$name] = $now;
                $this->lastMessageContent[$name] = $message;
                return false; // not blocked
        }

        // ─── Chat Event Handler ───────────────────────────────────────────

        /**
         * @priority HIGHEST
         */
        public function onChat(PlayerChatEvent $event) : void {
                $player = $event->getPlayer();
                $rawMessage = $event->getMessage();
                $name = $player->getName();

                // Spam mute check
                if ($this->isSpamMuted($player)) {
                        $remaining = $this->spamMuted[$name] - time();
                        $player->sendMessage(TF::RED . "Вы замьючены. Подождите {$remaining} сек.");
                        $event->cancel();
                        return;
                }

                // Max length check
                if (mb_strlen($rawMessage) > $this->maxMessageLength) {
                        $player->sendMessage(TF::RED . "Сообщение слишком длинное (макс. {$this->maxMessageLength} символов).");
                        $event->cancel();
                        return;
                }

                // Spam / cooldown check
                if ($this->checkSpam($player, $rawMessage)) {
                        $event->cancel();
                        return;
                }

                // Bad word filter
                $message = $this->filterMessage($rawMessage);

                // Get player's active channel
                $channel = $this->channels[$name] ?? "global";

                // Global chat mute check
                if ($this->chatMuted && !$player->hasPermission("sadcraft.chat.admin")) {
                        $player->sendMessage(TF::RED . "Чат сейчас замьючен.");
                        $event->cancel();
                        return;
                }

                // Get karma prefix
                $prefix = $this->getKarmaPrefix($player);

                // Format message
                $format = $this->formats[$channel] ?? $this->formats["global"] ?? "{player}: {message}";
                $formatted = str_replace(
                        ["{prefix}", "{player}", "{message}"],
                        [$prefix, $player->getDisplayName(), $message],
                        $format
                );

                // Cancel the default event — we handle distribution ourselves
                $event->cancel();

                // Distribute based on channel
                if ($channel === "local") {
                        $this->sendLocalChat($player, $formatted);
                } else {
                        $this->sendGlobalChat($player, $formatted, $channel);
                }
        }

        private function sendLocalChat(Player $sender, string $formatted) : void {
                $pos = $sender->getPosition();
                $radius = $this->localChatRadius;
                $sent = false;

                foreach ($this->getServer()->getOnlinePlayers() as $player) {
                        if ($player->getWorld()->getFolderName() !== $sender->getWorld()->getFolderName()) {
                                continue;
                        }
                        $dist = $player->getPosition()->distance($pos);
                        if ($dist <= $radius) {
                                $player->sendMessage($formatted);
                                $sent = true;
                        }
                }

                // Also log to console
                $this->getLogger()->info("[L] " . TF::clean($formatted));

                if (!$sent) {
                        $sender->sendMessage(TF::GRAY . "Рядом никого нет. Сообщение в локальном чате не получено.");
                }
        }

        private function sendGlobalChat(Player $sender, string $formatted, string $channel) : void {
                foreach ($this->getServer()->getOnlinePlayers() as $player) {
                        $player->sendMessage($formatted);
                }
                $this->getLogger()->info("[" . strtoupper(substr($channel, 0, 1)) . "] " . TF::clean($formatted));
        }

        // ─── Commands ─────────────────────────────────────────────────────

        public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool {
                if (!$sender instanceof Player) {
                        $sender->sendMessage(TF::RED . "Эту команду может использовать только игрок.");
                        return true;
                }

                switch ($command->getName()) {
                        case "chat":
                                return $this->handleChatCommand($sender, $args);

                        case "msg":
                                return $this->handleMsgCommand($sender, $args);

                        case "r":
                                return $this->handleReplyCommand($sender, $args);

                        case "mutechat":
                                return $this->handleMuteChatCommand($sender);
                }

                return false;
        }

        private function handleChatCommand(Player $player, array $args) : bool {
                if (empty($args)) {
                        $current = $this->channels[$player->getName()] ?? "global";
                        $player->sendMessage(TF::GOLD . "Ваш текущий канал: " . TF::WHITE . strtoupper($current));
                        $player->sendMessage(TF::GRAY . "Используйте: /chat <global|local|trade|help>");
                        return true;
                }

                $channel = strtolower($args[0]);
                if (!in_array($channel, self::CHANNELS, true)) {
                        $player->sendMessage(TF::RED . "Неизвестный канал: " . $args[0]);
                        $player->sendMessage(TF::GRAY . "Доступные каналы: global, local, trade, help");
                        return true;
                }

                $this->channels[$player->getName()] = $channel;

                $channelLabels = [
                        "global" => "Глобальный §7[§fG§7]",
                        "local"  => "Локальный §7[§eL§7]",
                        "trade"  => "Торговый §7[§aT§7]",
                        "help"   => "Помощь §7[§bH§7]",
                ];

                $label = $channelLabels[$channel] ?? $channel;
                $player->sendMessage(TF::GREEN . "Канал переключён на: " . $label);
                return true;
        }

        private function handleMsgCommand(Player $player, array $args) : bool {
                if (count($args) < 2) {
                        $player->sendMessage(TF::RED . "Используйте: /msg <игрок> <сообщение>");
                        return true;
                }

                $targetName = array_shift($args);
                $target = $this->getServer()->getPlayerByPrefix($targetName);

                if ($target === null) {
                        $player->sendMessage(TF::RED . "Игрок {$targetName} не найден.");
                        return true;
                }

                if ($target->getName() === $player->getName()) {
                        $player->sendMessage(TF::RED . "Нельзя отправить сообщение самому себе.");
                        return true;
                }

                $message = implode(" ", $args);

                // Max length check
                if (mb_strlen($message) > $this->maxMessageLength) {
                        $player->sendMessage(TF::RED . "Сообщение слишком длинное (макс. {$this->maxMessageLength} символов).");
                        return true;
                }

                // Filter
                $message = $this->filterMessage($message);

                // Format for sender
                $sendFormat = $this->formats["private_send"] ?? "§7[§dPM§7] §7Ты → §f{recipient}§7: §f{message}";
                $sendFormatted = str_replace(
                        ["{sender}", "{recipient}", "{message}"],
                        [$player->getDisplayName(), $target->getDisplayName(), $message],
                        $sendFormat
                );

                // Format for recipient
                $recvFormat = $this->formats["private_receive"] ?? "§7[§dPM§7] §f{sender} §7→ Ты: §f{message}";
                $recvFormatted = str_replace(
                        ["{sender}", "{recipient}", "{message}"],
                        [$player->getDisplayName(), $target->getDisplayName(), $message],
                        $recvFormat
                );

                $player->sendMessage($sendFormatted);
                $target->sendMessage($recvFormatted);

                // Store reply targets
                $this->replyTarget[$target->getName()] = $player->getName();
                $this->replyTarget[$player->getName()] = $target->getName();

                // Log PM to console
                $this->getLogger()->info("[PM] " . $player->getName() . " -> " . $target->getName() . ": " . $message);

                return true;
        }

        private function handleReplyCommand(Player $player, array $args) : bool {
                $name = $player->getName();

                if (!isset($this->replyTarget[$name])) {
                        $player->sendMessage(TF::RED . "Вам никто не писал личных сообщений.");
                        return true;
                }

                $targetName = $this->replyTarget[$name];
                $target = $this->getServer()->getPlayerByPrefix($targetName);

                if ($target === null) {
                        $player->sendMessage(TF::RED . "Игрок {$targetName} не в сети.");
                        unset($this->replyTarget[$name]);
                        return true;
                }

                if (empty($args)) {
                        $player->sendMessage(TF::RED . "Используйте: /r <сообщение>");
                        return true;
                }

                // Delegate to /msg logic
                $fullArgs = array_merge([$targetName], $args);
                return $this->handleMsgCommand($player, $fullArgs);
        }

        private function handleMuteChatCommand(Player $player) : bool {
                if (!$player->hasPermission("sadcraft.chat.admin")) {
                        $player->sendMessage(TF::RED . "У вас нет прав для этой команды.");
                        return true;
                }

                $this->chatMuted = !$this->chatMuted;

                if ($this->chatMuted) {
                        $this->getServer()->broadcastMessage(TF::RED . "§lЧат замьючен§r§c администратором " . $player->getDisplayName());
                } else {
                        $this->getServer()->broadcastMessage(TF::GREEN . "§lЧат размьючен§r§a администратором " . $player->getDisplayName());
                }

                return true;
        }

        // ─── Cleanup on Quit ──────────────────────────────────────────────

        /**
         * @priority MONITOR
         */
        public function onPlayerQuit(\pocketmine\event\player\PlayerQuitEvent $event) : void {
                $name = $event->getPlayer()->getName();
                unset(
                        $this->channels[$name],
                        $this->lastMessageTime[$name],
                        $this->lastMessageContent[$name],
                        $this->spamCount[$name],
                        $this->spamMuted[$name],
                        $this->replyTarget[$name]
                );
        }
}
