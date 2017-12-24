<?php

/**
* © R4KT, Skull3x and Muqsit.
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Lesser General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version. Additional permission of
* the authors may be required.
*
*/

namespace R4KT;

use onebone\economyapi\EconomyAPI;

use pocketmine\item\{Item, enchantment\Enchantment};
use pocketmine\utils\{Config, TextFormat as TF};
use pocketmine\plugin\PluginBase;
use pocketmine\command\{Command, CommandSender};
use pocketmine\Player;

class AuctionHouse extends PluginBase {

    protected $auctions = [];

    public function onLoad() {
       @mkdir($this->getDataFolder());
        $fileExistancy = array('config.yml', 'auctions.json');
        foreach ($fileExistancy as $file) {
            if (!file_exists($this->getDataFolder() . $file)) {
                file_put_contents($this->getDataFolder() . $file, $this->getResource($file));
            }
        }
    }

    /**
    * When the plugin is fully loaded.
    */
    public function onEnable() {
        $this->loadEverything();
    }

    /**
    * When the plugin gets disabled.
    */
    public function onDisable() {
        $this->saveEverything();
    }

    /**
    * Saves everything in 'auctions.json' file
    * before disabling the plugin to avoid data
    * loss.
    */
    public function saveEverything() {
        unlink($this->getDataFolder().'auctions.json'); //Avoiding duplication glitches.
        $data = new Config($this->getDataFolder() . 'auctions.json', Config::JSON);
        foreach ($this->auctions as $aucId => $aucData) {
            $data->set($aucId, $aucData);
        }
        $data->save();
    }

    /**
    * Loads everything that was saved in
    * 'auctions.json'.
    */
    public function loadEverything()
    {
        $data = new Config($this->getDataFolder() . 'auctions.json', Config::JSON);
        foreach ($data->getAll() as $aucId => $aucData) {
            $this->auctions[$aucId] = $aucData;
        }
    }

    /**
    * Prefix used for AuctionHouse messages.
    */
    public static function prefix($positive = true) : string {
        $colour = $positive ? TF::GREEN : TF::RED;
        return TF::AQUA.TF::BOLD.'AH '.TF::RESET.$colour;
    }

    public function sendAuctionList(Player $player) {
        $i = 0;
        foreach ($this->auctions as $id => $data) {
            if ($i > 10) return true;
            $player->sendMessage(TF::AQUA.'ID'.$id.'  =>  '.TF::YELLOW.$data['name'].'(x'.$data['count'].') for $'.$data['price'].' by '.TF::GREEN.$data['seller']);
            ++$i;
        }
    }

    /**
    * Sends '/ah help' message to $player.
    * Player $player.
    */
    public static function sendHelp(Player $player) {
        $border = str_repeat(TF::GOLD.'='.TF::GREEN.'-', 7).TF::GOLD.'=';
        $helps = [
            '§a/ah list' => '§2List all current auctions.',
            '§a/ah list <player>' => '§2List all auctions hosted by <player>',
            '§a/ah buy <auctionID>' => '§2Buy an item off auction',
            '§a/ah sell <price>' => '§2Sell your item in hand.',
            '§a/ah info <auctionID>' => '§2Get detailed information of an item.'
        ];
        $player->sendMessage($border);
        foreach ($helps as $cmd => $desc) {
            $player->sendMessage(TF::AQUA.$cmd.' '.TF::WHITE.$desc);
        }
        $player->sendMessage($border);
        return true;
    }

    /**
    * To sell $item on auction as $player for a $price.
    * Item $item, Player $player, int $price.
    */
    public function sellAuction($player, $item, $price) {
        $aucId = Utils::getFreeKey($this->auctions);
        $name = strtolower($player->getName());
        $cloned = Item::get($item->getId(), $item->getDamage());
        $itemname = $cloned->getName();
        $itemcount = $item->getCount();
        $itemens = $item->hasEnchantments() ? $item->getEnchantments() : null;
        $cname = $item->hasCustomName() ? $item->getName() : null;
        $ens = [];
        if (!is_null($itemens)) {
            foreach ($itemens as $en) {
                $ide = $en->getId();
                $level = $en->getLevel();
                $ens[] = [$ide, $level];
            }
        } else $ens = null;
        $auctiondata = [
            'id' => $item->getId(),
            'damage' => $item->getDamage(),
            'count' => $item->getCount(),
            'enchants' => $ens,
            'name' => $itemname,
            'customname' => $cname,
            'seller' => $name,
            'price' => $price
        ];
        $this->auctions[$aucId] = $auctiondata;
        $player->sendMessage(self::prefix().'§bYou have successfully placed your§3 '.$itemname.' (x'.$itemcount.') for $'.$price.' §bon auction.');
        $player->sendMessage(TF::GRAY.'§dYour auction ID is§5 '.TF::GREEN.$aucId.TF::GRAY.'.');
        return true;
    }

    /**
    * Construct an item from $auction.
    * $auction is an existing key in $this->auctions.
    * @return Item.
    */
    public static function constructItem($auction) : Item {
        $itemId = $auction['id'];
        $itemdmg = $auction['damage'];
        $itemcnt = $auction['count'];
        $itemens = $auction['enchants'];
        $itemname = $auction['customname'];
        $item = Item::get($itemId, $itemdmg, $itemcnt);
        if (!is_null($itemname)) {
            $item->setCustomName($itemname);
        }
        if (!is_null($itemens)) {
            foreach ($itemens as $enchant) {
                $en = Enchantment::getEnchantment($enchant[0]);
                $en->setLevel($enchant[1]);
                $item->addEnchantment($en);
            }
        }
        return $item;
    }

    /**
    * To buy an item off auction.
    * Auction can be traced through $aucId.
    * int $aucId, Player $player.
    */
    public function buyAuction($aucId, $player) {
        if (isset($this->auctions[$aucId])) {
            $auction = $this->auctions[$aucId];
            $itemprice = $auction['price'];
            $money = EconomyAPI::getInstance()->myMoney($player);

            if ($money <= $itemprice) {
                $player->sendMessage(self::prefix(false)."§4I'm sorry, but you don't have enough money to buy this item.");
                return false;
            } else EconomyAPI::getInstance()->reduceMoney($player, $auction['price']);

            $seller = $this->getServer()->getPlayer($auction['seller']);
            EconomyAPI::getInstance()->addMoney($seller, $auction['price']);
            $item = self::constructItem($auction);
            $player->getInventory()->addItem($item);
            $player->sendMessage(self::prefix().'§bYou have successfully purchased the §3item §boff auction.');
            if ($seller instanceof Player) {
               $seller->sendMessage(self::prefix().$player->getName().' §6has purchased your item§e ('.$auction['name'].') for $'.$auction['price']);
                return true;
            }
            unset($this->auctions[$aucId]);
        } else {
            $player->sendMessage(self::prefix(false).'§cThe auction with the ID§4 ('.$aucId.') §ccannot be found.');
            return true;
        }
    }

    /**
    * Auction commands.
    */
    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args) : bool{
        if (strtolower($cmd->getName()) === 'ah') {
            if (count($args) === 0) {
                self::sendHelp($sender);
            } else {
                switch (strtolower($args[0])) {
                    case 'help':
                        self::sendHelp($sender);
                        return true;
                        break;
                    case 'sell':
                        if (isset($args[1])) {
                            if (($item = $sender->getInventory()->getItemInHand())->getId() !== 0) {
                                $this->sellAuction($sender, $item, $args[1]);
                                $sender->getInventory()->remove($item);
                                return true;
                            }
                        } else {
                            $sender->sendMessage(TF::AQUA.'§a/ah sell '.TF::GRAY.'<price>'.PHP_EOL.TF::GRAY.'§2Put the item you are currently holding, in auction for '.TF::YELLOW.'$<price>');
                        }
                        return true;
                        break;
                    case 'buy':
                        if (isset($args[1])) {
                            $this->buyAuction($args[1], $sender);
                        } else {
                            $sender->sendMessage(TF::AQUA.'§a/ah buy '.TF::GRAY.'<auctionID>'.PHP_EOL.TF::GRAY.'§2Buy the item assigned '.TF::YELLOW.'<auctionID>'.TF::GRAY.' off auction.');
                        }
                        return true;
                        break;
                    case 'list':
                        if (!isset($args[1])) {
                            $this->sendAuctionList($sender);
                            $sender->sendMessage(self::prefix().TF::GRAY.'Use '.TF::YELLOW.'§a/ah list <sellername>'.TF::GRAY.' §2to find item by seller, '.TF::YELLOW.'§a/ah info <auctionid> '.TF::GRAY.'§2to get more information about an item.');
                            return true;
                        } else {
                            if (isset($args[1])) {
                                $expected = strtolower($args[1]);
                                $none = false;
                                foreach ($this->auctions as $id => $data) {
                                    if ($data['seller'] == $expected) {
                                        $sender->sendMessage(TF::AQUA.'ID'.$id.'  =>  '.TF::YELLOW.$data['name'].'(x'.$data['count'].') for $'.$data['price'].' by '.TF::GREEN.$data['seller']);
                                        $none = true;
                                        return true;
                                    }
                                }
                                if (!$none) {
                                    $sender->sendMessage(self::prefix(false).$args[1].' §6does not host any auctions.');
                                    return true;
                                }
                            }
                        }
                        break;
                    case 'info':
                        if (isset($args[1]) && is_numeric($args[1])) {
                            $id = $args[1];
                            if (isset($this->auctions[$id])) {
                                $auc = $this->auctions[$id];
                                $cname = explode(PHP_EOL, $auc['customname']);
                                $bb = TF::YELLOW.'[*]'.TF::DARK_GRAY.str_repeat('=', 30).TF::YELLOW.'[*]';
                                $used = $auc['damage'] > 0 ? 'Yes' : 'No';
                                $enchanted = isset($auc['enchant']) ? 'Yes' : 'No';
                                return true;

                                $sender->sendMessage($bb);
                                $sender->sendMessage(TF::AQUA.'Item: '.TF::GREEN.$auc['name'].TF::DARK_GRAY.' / '.TF::GREEN.$cname[0].TF::RESET.TF::GREEN.'(x'.$auc['count'].')'.PHP_EOL.
                                    TF::AQUA.'§aUsed Item:§2 '.$used.PHP_EOL.
                                    TF::AQUA.'§aEnchanted:§2 '.$enchanted.PHP_EOL.
                                    TF::AQUA.'§aCost:§2 $'.TF::GREEN.$auc['price'].PHP_EOL.
                                    TF::AQUA.'§aSeller:§2 '.TF::GREEN.$auc['seller']);
                                $sender->sendMessage($bb);
                                return true;

                            } else {
                                $sender->sendMessage(self::prefix(false).'§6The provided auction cannot be found.');
                                return true;
                            }
                        } else {
                            $sender->sendMessage(TF::AQUA.'§a/ah info '.TF::GRAY.'<auctionID>');
                            return true;
                        }
                        break;
                }
            }
        }
    }
}
