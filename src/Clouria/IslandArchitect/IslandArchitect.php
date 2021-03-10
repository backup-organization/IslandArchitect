<?php

/*
		
		  _____     _                 _          
		  \_   \___| | __ _ _ __   __| |         
		   / /\/ __| |/ _` | '_ \ / _` |         
		/\/ /_ \__ \ | (_| | | | | (_| |         
		\____/ |___/_|\__,_|_| |_|\__,_|         
		                                         
		   _            _     _ _            _   
		  /_\  _ __ ___| |__ (_) |_ ___  ___| |_ 
		 //_\\| '__/ __| '_ \| | __/ _ \/ __| __|
		/  _  \ | | (__| | | | | ||  __/ (__| |_ 
		\_/ \_/_|  \___|_| |_|_|\__\___|\___|\__|
		                                         
		@ClouriaNetwork | Apache License 2.0
														*/

declare(strict_types=1);
namespace Clouria\IslandArchitect;

use Clouria\IslandArchitect\{runtime\sessions\PlayerSession, runtime\TemplateIsland};
use muqsit\invmenu\InvMenuHandler;
use pocketmine\{Player, plugin\PluginBase, scheduler\ClosureTask, utils\TextFormat as TF};
use function array_search;
use function class_exists;

class IslandArchitect extends PluginBase {

	public const DEV_ISLAND = false;

	private static $instance = null;

	/**
	 * @var PlayerSession[]
	 */
	private $sessions = [];

	public function onLoad() : void {
		self::$instance = $this;
	}

	public function onEnable() : void {
		$this->initConfig();
		if (class_exists(InvMenuHandler::class)) if (!InvMenuHandler::isRegistered()) InvMenuHandler::register($this);
		$class = EventListener::getClass();
		$this->getServer()->getPluginManager()->registerEvents(new $class, $this);

		$class = IslandArchitectCommand::getClass();
		$this->getServer()->getCommandMap()->register($this->getName(), new $class);

		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(int $ct) : void {
		    foreach ($this->getSessions() as $s) if ($s->getIsland() !== null) {
		        $r = $s->getIsland()->getRandomByVector3($s->getPlayer()->getTargetBlock(12));
		        if ($r === null) continue;
		        $s->getPlayer()->sendPopup(TF::YELLOW . 'Random generation block: ' . TF::BOLD . TF::GOLD . $s->getIsland()->getRandomLabel($r));
            }
        }), 10);
	}

	private function initConfig() : void {
		$this->saveDefaultConfig();
		$conf = $this->getConfig();
		foreach ($all = $conf->getAll() as $k => $v) $conf->remove($k);

		$conf->set('island-data-folder', (string)($all['island-data-folder'] ?? $this->getDataFolder() . 'islands/'));
		$conf->set('panel-allow-unstable-item', (bool)($all['panel-allow-unstable-item'] ?? true));
		$conf->set('panel-default-seed', ($pds = $all['panel-default-seed'] ?? null) === null ? null : (int)$pds);
		$conf->set('island-creation-command-mapping', (array)($all['island-type-map'] ?? [
		    'generation-name-which-will-be' => 'exported-island-data-file.json',
            'use-in-island-creation-cmd' => 'relative-path/start-from/island-data-folder.json'
        ]));

		$conf->save();
		$conf->reload();
	}

    public function getSession(Player $player, bool $nonnull = false) : ?PlayerSession {
		if (self::DEV_ISLAND) $nonnull = true;
		if (($this->sessions[$player->getName()] ?? null) !== null) $s = $this->sessions[$player->getName()];
		elseif ($nonnull) {
			$s = ($this->sessions[$player->getName()] = new PlayerSession($player));
			if (self::DEV_ISLAND) $s->checkOutIsland(new TemplateIsland('test'));
		}
		return $s ?? null;
	}

	public function mapGeneratorType(string $type) : ?string {
	    $type = $this->getConfig()->get('island-creation-command-mapping')[$type] ?? null;
	    if (isset($type) and !file_exists($type)) $type = null;
	    return $type;
    }

    /**
     * @return PlayerSession[]
     */
	public function getSessions() : array {
	    return $this->sessions;
    }

    /**
     * @param PlayerSession $session
     * @return bool Return false if the session has already been disposed or not even in the sessions list
     */
    public function disposeSession(PlayerSession $session) : bool {
        if (($r = array_search($session, $this->sessions, true)) === false) return false;
        if ($this->sessions[$r]->getIsland()) $this->sessions[$r]->saveIsland();
        unset($this->sessions[$r]);
        return true;
    }

	public static function getInstance() : ?self {
		return self::$instance;
	}

}
