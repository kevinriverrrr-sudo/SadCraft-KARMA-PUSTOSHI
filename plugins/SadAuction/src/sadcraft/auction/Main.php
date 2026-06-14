<?php

declare(strict_types=1);

namespace sadcraft\auction;

use pocketmine\plugin\PluginBase;
use pocketmine\plugin\Plugin;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\item\Item;
use pocketmine\utils\Config;
use pocketmine\scheduler\Task;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;
use pocketmine\nbt\tag\CompoundTag;

class Main extends PluginBase{

	private Config $auctions;
	private Config $expired;
	private int $nextId = 1;

	private int $auctionDuration;
	private float $taxPercent;
	private int $maxActiveListings;
	private int $minPrice;
	private int $maxPrice;
	private int $expiredStorage;

	public function onEnable() : void{
		$this->saveDefaultConfig();

		$this->auctionDuration   = (int) $this->getConfig()->get("auction_duration", 3600);
		$this->taxPercent        = (float) $this->getConfig()->get("tax_percent", 5);
		$this->maxActiveListings = (int) $this->getConfig()->get("max_active_listings", 5);
		$this->minPrice          = (int) $this->getConfig()->get("min_price", 1);
		$this->maxPrice          = (int) $this->getConfig()->get("max_price", 1000000);
		$this->expiredStorage    = (int) $this->getConfig()->get("expired_storage", 86400);

		$this->auctions = new Config($this->getDataFolder() . "auctions.json", Config::JSON, []);
		$this->expired  = new Config($this->getDataFolder() . "expired.json", Config::JSON, []);

		// Determine the next auction ID from existing data
		$maxId = 0;
		foreach($this->auctions->getAll() as $entry){
			$maxId = max($maxId, (int) ($entry["id"] ?? 0));
		}
		foreach($this->expired->getAll() as $entry){
			$maxId = max($maxId, (int) ($entry["id"] ?? 0));
		}
		$this->nextId = $maxId + 1;

		// Schedule expired-auction check every 60 seconds (20 ticks/sec × 60)
		$this->getScheduler()->scheduleRepeatingTask(
			new class($this) extends Task{
				public function __construct(private Main $plugin){}

				public function onRun() : void{
					$this->plugin->checkExpiredAuctions();
				}
			},
			20 * 60
		);

		$this->getLogger()->info("SadAuction включён!");
	}

	public function onDisable() : void{
		$this->saveData();
	}

	private function saveData() : void{
		$this->auctions->save();
		$this->expired->save();
	}

	/* ======================================================================
	 *  Economy helpers — SadEconomy integration
	 * ==================================================================== */

	private function getEconomyPlugin() : ?Plugin{
		return $this->getServer()->getPluginManager()->getPlugin("SadEconomy");
	}

	private function getBalance(string $playerName) : float{
		$eco = $this->getEconomyPlugin();
		if($eco === null){
			return 0.0;
		}
		if(method_exists($eco, "getBalance")){
			return (float) $eco->getBalance($playerName);
		}
		if(method_exists($eco, "myMoney")){
			return (float) $eco->myMoney($playerName);
		}
		if(method_exists($eco, "getMoney")){
			return (float) $eco->getMoney($playerName);
		}
		return 0.0;
	}

	private function addBalance(string $playerName, float $amount) : bool{
		$eco = $this->getEconomyPlugin();
		if($eco === null){
			return false;
		}
		if(method_exists($eco, "addBalance")){
			$eco->addBalance($playerName, $amount);
			return true;
		}
		if(method_exists($eco, "addMoney")){
			return (bool) $eco->addMoney($playerName, $amount);
		}
		return false;
	}

	private function reduceBalance(string $playerName, float $amount) : bool{
		$eco = $this->getEconomyPlugin();
		if($eco === null){
			return false;
		}
		if(method_exists($eco, "reduceBalance")){
			return $eco->reduceBalance($playerName, $amount);
		}
		if(method_exists($eco, "reduceMoney")){
			return $eco->reduceMoney($playerName, $amount);
		}
		return false;
	}

	/* ======================================================================
	 *  Item serialisation / deserialisation
	 * ==================================================================== */

	private function serializeItem(Item $item) : string{
		$serializer = new BigEndianNbtSerializer();
		return base64_encode($serializer->write(new TreeRoot($item->nbtSerialize())));
	}

	private function deserializeItem(string $data) : ?Item{
		try{
			$decoded = base64_decode($data, true);
			if($decoded === false){
				return null;
			}
			$serializer = new BigEndianNbtSerializer();
			$root = $serializer->read($decoded);
			$tag = $root->getTag();
			if(!$tag instanceof CompoundTag){
				return null;
			}
			return Item::nbtDeserialize($tag);
		}catch(\Exception $e){
			$this->getLogger()->error("Failed to deserialize item: " . $e->getMessage());
			return null;
		}
	}

	/* ======================================================================
	 *  Command dispatcher
	 * ==================================================================== */

	public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
		if(!$sender instanceof Player){
			$sender->sendMessage("§cКоманда только для игроков!");
			return true;
		}

		$cmdName = strtolower($command->getName());

		switch($cmdName){
			case "ah":
				// /ah [page]  or  /ah sell|bid|cancel|expired ...
				if(count($args) > 0){
					$sub = strtolower((string) $args[0]);
					switch($sub){
						case "sell":
							return $this->handleSell($sender, array_slice($args, 1));
						case "bid":
							return $this->handleBid($sender, array_slice($args, 1));
						case "cancel":
							return $this->handleCancel($sender, array_slice($args, 1));
						case "expired":
							return $this->handleExpired($sender);
						default:
							// Treat first arg as page number
							return $this->handleList($sender, $args);
					}
				}
				return $this->handleList($sender, $args);

			case "ah sell":
				return $this->handleSell($sender, $args);
			case "ah bid":
				return $this->handleBid($sender, $args);
			case "ah cancel":
				return $this->handleCancel($sender, $args);
			case "ah expired":
				return $this->handleExpired($sender);
		}

		return false;
	}

	/* ======================================================================
	 *  /ah [page] — list active auctions
	 * ==================================================================== */

	private function handleList(Player $player, array $args) : bool{
		$page = 1;
		if(count($args) > 0){
			$page = max(1, (int) $args[0]);
		}

		$now = time();

		// Collect only active (non-expired) auctions
		$active = [];
		foreach($this->auctions->getAll() as $entry){
			if(($entry["expires"] ?? 0) > $now){
				$active[] = $entry;
			}
		}

		if(count($active) === 0){
			$player->sendMessage("§c§l[АУКЦИОН] §r§7Аукцион пуст — нет активных лотов.");
			return true;
		}

		$perPage    = 5;
		$totalPages = (int) ceil(count($active) / $perPage);
		if($page > $totalPages){
			$page = $totalPages;
		}

		$player->sendMessage("§c§l[АУКЦИОН] §r§7Страница §f{$page} §7из §f{$totalPages}");

		$offset = ($page - 1) * $perPage;
		$slice  = array_slice($active, $offset, $perPage);

		foreach($slice as $auction){
			$id           = $auction["id"];
			$itemName     = $auction["item_name"] ?? "Unknown";
			$count        = $auction["item_count"] ?? 1;
			$seller       = $auction["seller"] ?? "?";
			$price        = (float) ($auction["price"] ?? 0);
			$currentBid   = (float) ($auction["current_bid"] ?? $price);
			$currentBidder = $auction["current_bidder"] ?? null;

			$bidStr = $currentBidder !== null
				? "§bСтавка: §e" . number_format($currentBid) . " §7от §f" . $currentBidder
				: "§bСтавка: §e" . number_format($currentBid);

			$player->sendMessage(
				"§c§l[АУКЦИОН] §r§7Лот #{$id} §7| §f{$itemName} x{$count} §7| §e" .
				number_format($price) . " §7| §7{$seller} §7| {$bidStr}"
			);
		}

		if($page < $totalPages){
			$next = $page + 1;
			$player->sendMessage("§7Введите §f/ah {$next} §7для следующей страницы");
		}

		return true;
	}

	/* ======================================================================
	 *  /ah sell <price>
	 * ==================================================================== */

	private function handleSell(Player $player, array $args) : bool{
		if(count($args) < 1){
			$player->sendMessage("§cИспользование: /ah sell <цена>");
			return true;
		}

		$price = (float) $args[0];
		if($price < $this->minPrice || $price > $this->maxPrice){
			$player->sendMessage(
				"§cЦена должна быть от §e" . number_format($this->minPrice) .
				" §cдо §e" . number_format($this->maxPrice)
			);
			return true;
		}

		$item = $player->getInventory()->getItemInHand();
		if($item->isNull()){
			$player->sendMessage("§cУ вас в руке нет предмета!");
			return true;
		}

		$playerName = $player->getName();

		// Check active listings limit
		$activeCount = 0;
		foreach($this->auctions->getAll() as $entry){
			if(($entry["seller"] ?? "") === $playerName){
				$activeCount++;
			}
		}
		if($activeCount >= $this->maxActiveListings){
			$player->sendMessage("§cУ вас уже максимальное количество лотов (§e{$this->maxActiveListings}§c)!");
			return true;
		}

		$now = time();
		$id  = $this->nextId++;

		$auctionData = [
			"id"              => $id,
			"seller"          => $playerName,
			"item_type"       => $item->getTypeId(),
			"item_meta"       => $item->getMeta(),
			"item_count"      => $item->getCount(),
			"item_name"       => $item->getName(),
			"item_nbt"        => $this->serializeItem($item),
			"price"           => $price,
			"current_bid"     => $price,
			"current_bidder"  => null,
			"created"         => $now,
			"expires"         => $now + $this->auctionDuration,
		];

		$this->auctions->set((string) $id, $auctionData);
		$this->auctions->save();

		// Remove item from player's hand
		$player->getInventory()->setItemInHand($item->setCount(0));

		$player->sendMessage("§a§l[АУКЦИОН] §r§7Лот #{$id} выставлен за §e" . number_format($price) . "§7!");

		return true;
	}

	/* ======================================================================
	 *  /ah bid <id> <price>
	 * ==================================================================== */

	private function handleBid(Player $player, array $args) : bool{
		if(count($args) < 2){
			$player->sendMessage("§cИспользование: /ah bid <id> <ставка>");
			return true;
		}

		$auctionId = (int) $args[0];
		$bidAmount = (float) $args[1];

		$auction = $this->auctions->get((string) $auctionId);
		if($auction === null){
			$player->sendMessage("§cЛот #{$auctionId} не найден!");
			return true;
		}

		// Check if expired
		if(($auction["expires"] ?? 0) <= time()){
			$player->sendMessage("§cЛот #{$auctionId} уже истёк!");
			return true;
		}

		$playerName = $player->getName();

		// Cannot bid on own auction
		if(($auction["seller"] ?? "") === $playerName){
			$player->sendMessage("§cНельзя ставить на свой же лот!");
			return true;
		}

		// Bid must exceed current bid
		$currentBid = (float) ($auction["current_bid"] ?? $auction["price"] ?? 0);
		if($bidAmount <= $currentBid){
			$player->sendMessage("§cСтавка должна быть больше текущей §e" . number_format($currentBid) . "§c!");
			return true;
		}

		// Check bidder balance
		$balance = $this->getBalance($playerName);
		if($balance < $bidAmount){
			$player->sendMessage("§cУ вас недостаточно средств! Баланс: §e" . number_format($balance));
			return true;
		}

		// Refund previous bidder
		$prevBidder = $auction["current_bidder"] ?? null;
		if($prevBidder !== null){
			$prevBid = (float) ($auction["current_bid"] ?? 0);
			$this->addBalance($prevBidder, $prevBid);

			// Notify previous bidder if online
			$prevPlayer = $this->getServer()->getPlayerByPrefix($prevBidder);
			if($prevPlayer !== null){
				$prevPlayer->sendMessage("§e§l[АУКЦИОН] §r§7Ваша ставка на лот #{$auctionId} перебита! Деньги возвращены.");
			}
		}

		// Deduct money from new bidder
		if(!$this->reduceBalance($playerName, $bidAmount)){
			$player->sendMessage("§cНе удалось списать средства! Попробуйте позже.");
			return true;
		}

		// Update auction data
		$auction["current_bid"]    = $bidAmount;
		$auction["current_bidder"] = $playerName;
		$this->auctions->set((string) $auctionId, $auction);
		$this->auctions->save();

		$player->sendMessage("§a§l[АУКЦИОН] §r§7Ставка §e" . number_format($bidAmount) . " §7на лот #{$auctionId} принята!");

		// Notify the seller
		$seller       = $auction["seller"] ?? null;
		$sellerPlayer = $seller !== null ? $this->getServer()->getPlayerByPrefix($seller) : null;
		if($sellerPlayer !== null){
			$sellerPlayer->sendMessage(
				"§e§l[АУКЦИОН] §r§f{$playerName} §7поставил §e" .
				number_format($bidAmount) . " §7на ваш лот #{$auctionId}!"
			);
		}

		return true;
	}

	/* ======================================================================
	 *  /ah cancel <id>
	 * ==================================================================== */

	private function handleCancel(Player $player, array $args) : bool{
		if(count($args) < 1){
			$player->sendMessage("§cИспользование: /ah cancel <id>");
			return true;
		}

		$auctionId = (int) $args[0];
		$auction   = $this->auctions->get((string) $auctionId);

		if($auction === null){
			$player->sendMessage("§cЛот #{$auctionId} не найден!");
			return true;
		}

		$playerName = $player->getName();

		// Only the seller (or an admin) can cancel
		if(($auction["seller"] ?? "") !== $playerName && !$player->hasPermission("sadcraft.auction.admin")){
			$player->sendMessage("§cВы можете снимать только свои лоты!");
			return true;
		}

		// Refund current bidder if one exists
		$currentBidder = $auction["current_bidder"] ?? null;
		if($currentBidder !== null){
			$currentBid = (float) ($auction["current_bid"] ?? 0);
			$this->addBalance($currentBidder, $currentBid);

			$bidderPlayer = $this->getServer()->getPlayerByPrefix($currentBidder);
			if($bidderPlayer !== null){
				$bidderPlayer->sendMessage("§e§l[АУКЦИОН] §r§7Лот #{$auctionId} был снят, ваша ставка возвращена!");
			}
		}

		// Return the item to the seller
		$item = $this->deserializeItem($auction["item_nbt"] ?? "");
		if($item !== null){
			$seller       = $auction["seller"] ?? "";
			$sellerPlayer = $this->getServer()->getPlayerByPrefix($seller);
			if($sellerPlayer !== null){
				$sellerPlayer->getInventory()->addItem($item);
				if($sellerPlayer->getName() !== $playerName){
					$sellerPlayer->sendMessage("§a§l[АУКЦИОН] §r§7Предмет из лота #{$auctionId} возвращён вам (лот снят администратором).");
				}
			}else{
				// Seller is offline — store in expired for later claim
				$this->addToExpired($seller, $auction);
			}
		}

		$this->auctions->remove((string) $auctionId);
		$this->auctions->save();

		$player->sendMessage("§a§l[АУКЦИОН] §r§7Лот #{$auctionId} снят с аукциона!");

		return true;
	}

	/* ======================================================================
	 *  /ah expired — claim expired / unsold items
	 * ==================================================================== */

	private function handleExpired(Player $player) : bool{
		$playerName  = $player->getName();
		$allExpired  = $this->expired->getAll();
		$playerItems = [];

		foreach($allExpired as $key => $data){
			if(($data["owner"] ?? "") === $playerName){
				$playerItems[(string) $key] = $data;
			}
		}

		if(count($playerItems) === 0){
			$player->sendMessage("§c§l[АУКЦИОН] §r§7У вас нет предметов для получения.");
			return true;
		}

		$claimed = 0;
		foreach($playerItems as $key => $data){
			$item = $this->deserializeItem($data["item_nbt"] ?? "");
			if($item === null){
				$this->expired->remove((string) $key);
				continue;
			}

			// Try to add to inventory; if full, drop at player position
			$added = $player->getInventory()->addItem($item);
			if(count($added) > 0){
				// Some items didn't fit — drop them
				foreach($added as $dropItem){
					$player->getWorld()->dropItem($player->getPosition(), $dropItem);
				}
			}

			$this->expired->remove((string) $key);
			$claimed++;
		}

		$this->expired->save();

		$player->sendMessage("§a§l[АУКЦИОН] §r§7Вы получили §f{$claimed} §7предмет(ов)!");

		return true;
	}

	/* ======================================================================
	 *  Scheduled task — check and process expired auctions
	 * ==================================================================== */

	public function checkExpiredAuctions() : void{
		$now = time();
		$all = $this->auctions->getAll();

		foreach($all as $id => $auction){
			if(($auction["expires"] ?? 0) <= $now){
				$this->processExpiredAuction($auction);
				$this->auctions->remove((string) $id);
			}
		}

		// Purge expired items that have exceeded their storage time
		$allExpired = $this->expired->getAll();
		foreach($allExpired as $key => $data){
			$expireTime = (int) ($data["expire_time"] ?? 0);
			if($expireTime > 0 && $expireTime <= $now){
				$this->expired->remove((string) $key);
			}
		}

		$this->saveData();
	}

	private function processExpiredAuction(array $auction) : void{
		$currentBidder = $auction["current_bidder"] ?? null;
		$seller        = $auction["seller"] ?? "Unknown";

		if($currentBidder !== null){
			// ── Has bidder → transfer item to winner, pay seller ──
			$finalPrice = (float) ($auction["current_bid"] ?? 0);
			$tax        = $finalPrice * ($this->taxPercent / 100);
			$payout     = $finalPrice - $tax;

			// Give item to the winner
			$item   = $this->deserializeItem($auction["item_nbt"] ?? "");
			$winner = $this->getServer()->getPlayerByPrefix($currentBidder);

			if($item !== null){
				if($winner !== null){
					$overflow = $winner->getInventory()->addItem($item);
					if(count($overflow) > 0){
						foreach($overflow as $dropItem){
							$winner->getWorld()->dropItem($winner->getPosition(), $dropItem);
						}
					}
					$winner->sendMessage("§a§l[АУКЦИОН] §r§7Вы выиграли лот #{$auction["id"]}! Предмет получен.");
				}else{
					// Winner is offline — store for later claim
					$this->addToExpired($currentBidder, $auction);
				}
			}

			// Pay the seller (after tax)
			$this->addBalance($seller, $payout);

			$sellerPlayer = $this->getServer()->getPlayerByPrefix($seller);
			if($sellerPlayer !== null){
				$sellerPlayer->sendMessage(
					"§a§l[АУКЦИОН] §r§7Ваш лот #{$auction["id"]} продан за §e" .
					number_format($payout) . "§7 (комиссия §e" . number_format($tax) . "§7)!"
				);
			}
		}else{
			// ── No bidder → move item to expired storage for seller ──
			$this->addToExpired($seller, $auction);

			$sellerPlayer = $this->getServer()->getPlayerByPrefix($seller);
			if($sellerPlayer !== null){
				$sellerPlayer->sendMessage(
					"§e§l[АУКЦИОН] §r§7Ваш лот #{$auction["id"]} истёк без ставок. Заберите предмет: §f/ah expired"
				);
			}
		}
	}

	/* ======================================================================
	 *  Expired-item storage helpers
	 * ==================================================================== */

	private function addToExpired(string $owner, array $auction) : void{
		$this->expired->set((string) $auction["id"], [
			"id"          => $auction["id"],
			"owner"       => $owner,
			"item_type"   => $auction["item_type"] ?? 0,
			"item_meta"   => $auction["item_meta"] ?? 0,
			"item_count"  => $auction["item_count"] ?? 1,
			"item_name"   => $auction["item_name"] ?? "Unknown",
			"item_nbt"    => $auction["item_nbt"] ?? "",
			"expire_time" => time() + $this->expiredStorage,
		]);
	}
}
