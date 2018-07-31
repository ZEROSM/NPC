<?php

/*
 * Copyright (C) 2015-2018 onebone <jyc00410@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/

namespace onebone\npc;

use pocketmine\lang\TranslationContainer;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\plugin\PluginBase;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\utils\TextFormat;
use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\Player;

class Main extends PluginBase implements Listener{
	/** @var NPC[] */
	private $npc = [];
	private $msgQueue = [], $cmdQueue = [];

	public function onEnable(){
		if(!file_exists($this->getDataFolder())){
			mkdir($this->getDataFolder());
		}
		$this->saveDefaultConfig();

		if(!file_exists($this->getDataFolder()."skins")){
			mkdir($this->getDataFolder()."skins");
		}
		if(!is_file($this->getDataFolder()."npc.dat")){
			file_put_contents($this->getDataFolder()."npc.dat", serialize([]));
		}
		$data = unserialize(file_get_contents($this->getDataFolder()."npc.dat"));
		$this->npc = [];

		foreach($data as $datam){
			$skinFile = $this->getDataFolder()."skins/".$datam[6].".skin";
			$datam[6] = file_get_contents($skinFile);
			$npc = NPC::createNPC($this, $datam);
			$this->npc[$npc->getId()] = $npc;
		}

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	public function onPacketReceived(DataPacketReceiveEvent $event){
		$pk = $event->getPacket();
		if($pk instanceof InventoryTransactionPacket and $pk->transactionType === InventoryTransactionPacket::TYPE_USE_ITEM_ON_ENTITY){
			if(isset($this->npc[$pk->trData->entityRuntimeId])){
				$npc = $this->npc[$pk->trData->entityRuntimeId];

				if(!isset($this->msgQueue[$event->getPlayer()->getName()]) and !isset($this->cmdQueue[$event->getPlayer()->getName()])){
					$npc->onInteract($event->getPlayer());
					return;
				}

				if(isset($this->msgQueue[$event->getPlayer()->getName()])){
					$npc->setMessage($this->msgQueue[$event->getPlayer()->getName()]);
					unset($this->msgQueue[$event->getPlayer()->getName()]);
					$event->getPlayer()->sendMessage("NPC ".TextFormat::AQUA.$npc->getName().TextFormat::WHITE."가 플레이어한테 보여줄 메시지가 설정되었습니다.");
					$event->getPlayer()->sendMessage("내용: ".TextFormat::GREEN.$npc->getMessage());
				}

				if(isset($this->cmdQueue[$event->getPlayer()->getName()])){
					$npc->setCommand($this->cmdQueue[$event->getPlayer()->getName()]);
					unset($this->cmdQueue[$event->getPlayer()->getName()]);
					$event->getPlayer()->sendMessage("NPC ".TextFormat::AQUA.$npc->getName().TextFormat::WHITE."가 실행할 명령어가 설정되었습니다.");
					$event->getPlayer()->sendMessage("명령어: " . TextFormat::GREEN . "/" . $npc->getCommand());
				}
			}
		}
	}

	public function onPlayerJoin(PlayerJoinEvent $event){
		foreach($this->npc as $npc){
			if($npc->getLevel()->getFolderName() === $event->getPlayer()->getLevel()->getFolderName()){
				$npc->spawnTo($event->getPlayer());
			}
		}
	}

	public function onMoveEvent(PlayerMoveEvent $event){
		$player = $event->getPlayer();

		foreach($this->npc as $npc){
			if($npc->getLevel()->getFolderName() === $event->getPlayer()->getLevel()->getFolderName()){
				$npc->seePlayer($player);
			}
		}
	}

	public function onEntityTeleport(EntityTeleportEvent $event){
		$player = $event->getEntity();

		if($player instanceof Player){
			if($event->getFrom()->getLevel()->getFolderName() !== ($toLevel = $event->getTo()->getLevel()->getFolderName())){
				foreach($this->npc as $npc){
					if($npc->getLevel()->getFolderName() === $toLevel){
						$npc->spawnTo($player);
					}else{
						$npc->removeFrom($player);
					}
				}
			}
		}
	}

	public function onCommand(CommandSender $sender, Command $command, string $label, array $params) : bool {
		switch($command->getName()){
			case "npc":
			switch(strtolower(array_shift($params))){
				case "생성":
				case "c":
					if(!$sender instanceof Player){
						$sender->sendMessage(TextFormat::RED . "Please run this command in-game.");
						return true;
					}

					if(!$sender->hasPermission("npc.command.npc.create")){
						$sender->sendMessage(new TranslationContainer(TextFormat::RED . "%commands.generic.permission"));
						return true;
					}

					$name = implode(" ", $params);
					if(trim($name) === ""){
						$sender->sendMessage(TextFormat::RED."사용법: /npc 생성 <name>");
						return true;
					}

					$npc = new NPC($this, $sender->asLocation(), $name, $sender->getSkin(), $sender->getInventory()->getItemInHand());
					$this->npc[$npc->getId()] = $npc;
					foreach($sender->getLevel()->getPlayers() as $player){
						$npc->spawnTo($player);
					}

					if($this->getConfig()->get("save-on-change")){
						$this->save();
					}
					return true;
					case "제거":
					case "r":
					if(!$sender->hasPermission("npc.command.npc.remove")){
						$sender->sendMessage(new TranslationContainer(TextFormat::RED . "%commands.generic.permission"));
						return true;
					}

					$id = array_shift($params);
					if(!is_numeric($id)){
						$sender->sendMessage(TextFormat::RED."Usage: /npc 제거 <id>");
						return true;
					}

					foreach($this->npc as $key => $npc){
						if($id == $npc->getId()){
							$npc->remove();
							unset($this->npc[$key]);
							$sender->sendMessage("Removed NPC ".TextFormat::AQUA.$npc->getName());
							if($this->getConfig()->get("save-on-change")){
								$this->save();
							}
							return true;
						}
					}
					$sender->sendMessage("해당 ".TextFormat::RED.$id."§r§f NPC 번호는 NPC 목록에 없습니다.");
				return true;
				case "목록":
				case "ls":
				case "l":
					if(!$sender->hasPermission("npc.command.npc.list")){
						$sender->sendMessage(new TranslationContainer(TextFormat::RED . "%commands.generic.permission"));
						return true;
					}

					$page = array_shift($params);
					if(!is_numeric($page)){
						$page = 1;
					}

					$max = ceil(count($this->npc)/5);
					$page = (int)$page;
					$page = max(1, min($page, $max));

					$output = "Showing NPC list (page $page/$max): \n";
					$n = 0;
					foreach($this->npc as $id => $npc){
						$current = (int)ceil(++$n / 5);

						if($current === $page){
							$output .= "#".$npc->getId()
							." (".round($npc->x, 2).":".round($npc->y, 2).":".round($npc->z, 2).":".$npc->getLevel()->getName()."): "
							.$npc->getName()."\n";
						}elseif($current > $page) break;
					}
					$output = substr($output, 0, -1);
					$sender->sendMessage($output);
					return true;
				case "메시지":
				case "msg":
				case "m":
					if(!$sender->hasPermission("npc.command.npc.message")){
						$sender->sendMessage(new TranslationContainer(TextFormat::RED . "%commands.generic.permission"));
						return true;
					}

					$message = trim(implode(" ", $params));

					$this->msgQueue[$sender->getName()] = $message;

					$sender->sendMessage("해당 메시지를 적용할 NPC를 터치해주세요!");
					if($this->getConfig()->get("save-on-change")){
						$this->save();
					}
					return true;
				case "명령어":
				case "cmd":
					if(!$sender->hasPermission("npc.command.npc.command")){
						$sender->sendMessage(new TranslationContainer(TextFormat::RED . "%commands.generic.permission"));
						return true;
					}

					$this->cmdQueue[$sender->getName()] = trim(implode(" ", $params));
					$sender->sendMessage("해당 명령어를 적용할 NPC를 터치해주세요!");
					return true;
			}
		}
		return false;
	}

	public function onDisable(){
		$this->save();
	}

	public function save(){
		$dir = scandir($this->getDataFolder()."skins/");
		foreach($dir as $file){
			if($file !== "." and $file !== ".."){
				unlink($this->getDataFolder()."skins/".$file);
			}
		}

		$save = [];
		foreach($this->npc as $npc){
			$data = $npc->getSaveData();
			file_put_contents($this->getDataFolder()."skins/".$data[6].".skin", $npc->getSkin());
			$save[] = $data;
		}
		file_put_contents($this->getDataFolder()."npc.dat", serialize($save));
	}
}
