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
namespace Clouria\IslandArchitect\runtime\sessions;

use pocketmine\{
    Player,
    Server,
    item\Item,
    level\Level,
    math\Vector3,
    scheduler\ClosureTask,
    utils\TextFormat as TF,
    level\particle\FloatingTextParticle};

use jojoe77777\FormAPI\{
    ModalForm,
    SimpleForm,
    CustomForm
};

use Clouria\IslandArchitect\{
    IslandArchitect,
    runtime\TemplateIsland,
    runtime\RandomGeneration,
    conversion\IslandDataEmitTask};

use function max;
use function min;
use function count;
use function round;
use function get_class;
use function microtime;
use function class_exists;
use function array_values;
use function spl_object_id;

class PlayerSession {

    public const FLOATINGTEXT_SPAWN = 0;
    public const FLOATINGTEXT_STARTCOORD = 1;
    public const FLOATINGTEXT_ENDCOORD = 2;

    /**
	 * @var Player
	 */
	private $player;

    /**
     * @var array<mixed, FloatingTextParticle>
     */
    protected $floatingtext = [];

    /**
     * @var scalar[]
     */
    protected $viewingft = [];

    public function __construct(Player $player) {
		$this->player = $player;
	}

	public function getPlayer() : Player {
		return $this->player;
	}

	/**
	 * @var TemplateIsland|null
	 */
	protected $island = null;

	public function checkOutIsland(TemplateIsland $island) : void {
		if ($this->export_lock) {
            $this->getPlayer()->sendMessage(TF::BOLD . TF::RED . 'An island is exporting in background, please wait until the island export is finished!');
            return;
        }
		$this->island = $island;

		$spawn = $island->getSpawn();
		if ($spawn === null) return;
		$spawn = $spawn->floor()->add(0.5, 0.5, 0.5);
		$ft = $this->getFloatingText(self::FLOATINGTEXT_SPAWN, true);
        $ft->setComponents($spawn->getX(), $spawn->getY(), $spawn->getZ());
        $ft->setText(TF::BOLD . TF::GOLD . 'Island spawn' . "\n" . TF::RESET . TF::GREEN . $spawn->getFloorX() . ', ' . $spawn->getFloorY() . ', ' . $spawn->getFloorZ());
	}

	public function getIsland() : ?TemplateIsland {
		return $this->island;
	}

	public function close() : void {
        $this->saveIsland();
        if (!$this->getPlayer()->isOnline()) return;
        foreach ($this->floatingtext as $ft) {
            $ft->setInvisible();
            $this->getPlayer()->getLevel()->addParticle($ft, [$this->getPlayer()]);
        }
	}

	/**
	 * @var bool
	 */
	protected $save_lock = false;

	public function saveIsland() : void {
		if ($this->save_lock) return;
		if (($island = $this->getIsland()) === null) return;
		if (!$island->hasChanges()) return;
		$this->save_lock = true;
		$time = microtime(true);
		IslandArchitect::getInstance()->getLogger()->debug('Saving island "' . $island->getName() . '" (' . spl_object_id($island) . ')');
		$task = new IslandDataEmitTask($island, [], function() use ($island, $time) : void {
			$this->save_lock = false;
			IslandArchitect::getInstance()->getLogger()->debug('Island "' . $island->getName() . '" (' . spl_object_id($island) . ') save completed (' . round(microtime(true) - $time, 2) . 's)');
			$island->noMoreChanges();
		});
		Server::getInstance()->getAsyncPool()->submitTask($task);
	}

	/**
	 * @var bool
	 */
	private $export_lock = false;

	public function exportIsland() : void {
		if (($island = $this->getIsland()) === null) return;
		if (!$island->readyToExport()) {
			$this->getPlayer()->sendMessage(TF::BOLD . TF::RED . 'Please set the island start and end coordinate first!');
			return;
		}
		$this->export_lock = true;
		$this->island = null;
		$time = microtime(true);
		$this->getPlayer()->sendMessage(TF::YELLOW . 'Queued export task for island "' . $island->getName() . '"...');

		$sc = $island->getStartCoord();
		$ec = $island->getEndCoord();

		for ($x=min($sc->getFloorX(), $ec->getFloorX()) >> 4; $x <= (max($sc->getFloorX(), $ec->getFloorX()) >> 4); $x++) for ($z=min($sc->getFloorZ(), $ec->getFloorZ()) >> 4; $z <= (max($sc->getFloorZ(), $ec->getFloorZ()) >> 4); $z++) {
			while (($level = Server::getInstance()->getLevelByName($island->getLevel())) === null) {
				if ($wlock ?? false) {
					$this->getPlayer()->sendMessage(TF::BOLD . TF::RED . 'Island world (' . $island->getLevel() . ') is missing!');
					$this->getPlayer()->sendMessage(TF::BOLD . TF::RED . 'Export task aborted.');
					$this->export_lock = false;
					return;
				}
				Server::getInstance()->loadLevel($island->getLevel());
				$wlock = true;
			}
			$chunk = $level->getChunk($x, $z, true);
			if ($chunk === null) $this->getPlayer()->sendMessage(TF::BOLD . TF::YELLOW . 'Warning: ' . TF::RED . 'Failed to load required chunk ' . $x . ', ' . $z);
			else {
				$chunks[0][$hash = Level::chunkHash($x, $z)] = $chunk->fastSerialize();
				$chunks[1][$hash] = get_class($chunk);
			}
		}
		if (!isset($chunks)) {
		    $this->getPlayer()->sendMessage(TF::BOLD . TF::RED . 'Critical: Failed to load required chunks');
		    return;
        }
		$this->getPlayer()->sendMessage(TF::GOLD . 'Start exporting...');
		$task = new IslandDataEmitTask($island, $chunks, function() use ($time) : void {
			$this->export_lock = false;
			$this->getPlayer()->sendMessage(TF::BOLD . TF::GREEN . 'Export completed!' . TF::ITALIC . TF::GRAY . ' (' . round(microtime(true) - $time, 2) . 's)');
		});
		$checker = null;
		$checker = IslandArchitect::getInstance()->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(int $ct) use (&$checker, $task, &$island) : void {
			if ($task->isCrashed()) {
				$this->export_lock = false;
				$this->getPlayer()->sendMessage(TF::BOLD . TF::RED . 'Critical: Export task crashed' . TF::ITALIC . TF::GRAY . ' (The selected region might be too big or an unexpected error occurred)');
				$this->getPlayer()->sendMessage(TF::BOLD . TF::GOLD . 'Attempting to recover original island settings...');
				$this->checkOutIsland($island);
				$restore = new IslandDataEmitTask($island, [], function() : void {
					$this->getPlayer()->sendMessage(TF::BOLD . TF::GREEN . 'Restore succeed!');
				});
				Server::getInstance()->getAsyncPool()->submitTask($restore);
			}
			if (!$this->export_lock) $checker->cancel();
		}), 10);

		Server::getInstance()->getAsyncPool()->submitTask($task);
	}

    /**
     * @param Player $player
     * @param PlayerSession|null $session
     * @return bool true = No island checked out
     */
	public static function errorCheckOutRequired(Player $player, ?PlayerSession $session) : bool {
		if ($session !== null and $session->getIsland() !== null) return false;
		$player->sendMessage(TF::BOLD . TF::RED . 'Please check out an island first!' . TF::GRAY . TF::ITALIC . ' ("/ia island <Island data file name: string>")');
		return true;
	}

    /**
     * @param scalar $id
     * @param bool $nonnull
     * @return FloatingTextParticle|null
     */
    public function getFloatingText($id, bool $nonnull = false) : ?FloatingTextParticle {
	    if (isset($this->floatingtext[$id])) return $this->floatingtext[$id];
	    if ($nonnull) return ($this->floatingtext[$id] = new FloatingTextParticle(new Vector3(0, 0, 0), ''));
	    return null;
    }

    /**
     * @param scalar $id
     * @return bool
     */
    public function showFloatingText($id) : bool {
	    if (!isset($this->floatingtext[$id])) return false;
	    if (in_array($id, $this->viewingft, true)) return false;
	    $this->viewingft[] = $id;

	    $ft = $this->floatingtext[$id];
	    $ft->setInvisible(false);
	    $this->getPlayer()->getLevel()->addParticle($ft, [$this->getPlayer()]);
	    return true;
    }

    /**
     * @param scalar $id
     * @return bool
     */
    public function hideFloatingText($id) : bool {
        if (!isset($this->floatingtext[$id])) return false;
	    if (($r = array_search($id, $this->viewingft, true)) === false) return false;
        unset($this->viewingft[$r]);

	    $ft = $this->floatingtext[$id];
	    $ft->setInvisible(true);
	    $this->getPlayer()->getLevel()->addParticle($ft, [$this->getPlayer()]);
	    return true;
    }

    public function listRandoms() : void {
		if ($this->getIsland() === null) return;
		if (class_exists(SimpleForm::class)) {
			$f = new SimpleForm(function(Player $p, int $d = null) : void {
				if ($d === null) return;
				if ($d <= count($this->getIsland()->getRandoms()) or count($this->getIsland()->getRandoms()) < 0x7fffffff) {
				    if ($this->getIsland()->getRandomById($d) === null) $this->editRandom(null);
				    else $this->editRandom($d);
                }
			});
			foreach ($this->getIsland()->getRandoms() as $i => $r) $f->addButton(TF::BOLD . TF::DARK_BLUE . $this->getIsland()->getRandomLabel($i) . "\n" . TF::RESET . TF::ITALIC . TF::DARK_GRAY . '(' . count($r->getAllElements()) . ' elements)');
			$f->setTitle(TF::BOLD . TF::DARK_AQUA . 'Regex List');
			$f->addButton(count($this->getIsland()->getRandoms()) < 0x7fffffff ? TF::BOLD . TF::DARK_GREEN . 'New Regex' : TF::BOLD . TF::DARK_GRAY . 'Max limit reached' . "\n" . TF::RESET . TF::ITALIC . TF::GRAY . '(2147483647 regex)');
			$this->getPlayer()->sendForm($f);
		} else $this->editRandom();
	}

    public function editRandom(?int $regexid = null) : bool {
        if ($regexid === null and count($this->getIsland()->getRandoms()) < 0x7fffffff) $regexid = $this->getIsland()->addRandom(new RandomGeneration);
        // 2147483647, max limit of int tag value and random generation regex number
        elseif ($regexid === null) return false;
        $form = new SimpleForm(function (Player $p, int $d = null) use ($regexid) : void {
            if ($d === null) {
                $this->listRandoms();
                return;
            }
            $r = $this->getIsland()->getRandomById($regexid);
            if ($r === null) {
                $this->getPlayer()->sendMessage(TF::BOLD . TF::RED . 'The target random generation regex has been removed!');
                return;
            }
            switch ($d) {
                case 0:
                    $this->editRandomElements($regexid);
                    break;

                case 1:
                    $this->editRandomLabel($regexid);
                    break;

                case 2:
                    $this->editRandomSymbolic($regexid);
                    break;

                case 3:
                    $this->getPlayer()->getInventory()->addItem($r->getRandomGenerationItem($this->getIsland()->getRandomSymbolicItem($regexid)));
                    $this->getPlayer()->sendMessage(TF::BOLD . TF::YELLOW . 'The random generation block item has been added to your inventory');
                    break;

                case 4:
                    new GenerationPreviewSession($this, $r, function() use ($regexid) : void {
                        $this->editRandom($regexid);
                    });
                    break;

                case 5:
                    $form = new ModalForm(function (Player $p, bool $d) use ($regexid) : void {
                        if ($d) {
                            $this->getIsland()->removeRandomById($regexid);
                            $this->listRandoms();
                        } else $this->editRandom($regexid);
                    });
                    $form->setTitle(TF::BOLD . TF::DARK_RED . 'Delete Confirmation');
                    $form->setContent(TF::YELLOW . 'Are you sure to ' . TF::BOLD . TF::RED . 'remove' . TF::RESET . TF::YELLOW . ' random generation regex ' . TF::GOLD . '#' . $regexid .
                        (($label = $this->getIsland()->getRandomLabel($regexid, true)) === null ? '' : TF::ITALIC . ' (' . $label. ')') . TF::RESET . TF::YELLOW .
                        ' from your current checked out island ' . TF::BOLD . TF::GOLD . '"' . $this->getIsland()->getName() . '"?'
                    );
                    $form->setButton1('gui.yes');
                    $form->setButton2('gui.no');
                    $this->getPlayer()->sendForm($form);
                    break;
            }
        });
        $form->setTitle(TF::BOLD . TF::DARK_AQUA . 'Modify Regex');
        $form->addButton(TF::DARK_BLUE . 'Modify content');
        $form->addButton(TF::DARK_BLUE . 'Update label');
        $form->addButton(TF::DARK_BLUE . 'Change symbolic');
        $form->addButton(TF::BLUE . 'Claim random' . "\n" . 'generation block');
        $form->addButton(TF::BLUE . 'Preview generation');
        $form->addButton(TF::BOLD . TF::DARK_RED . 'Remove regex');
        $this->getPlayer()->sendForm($form);
        return true;
    }

    public function editRandomElements(int $regexid) : void {
        $r = $this->getIsland()->getRandomById($regexid);
        $elements = $r->getAllElements();
        $form = new SimpleForm(function (Player $p, int $d = null) use ($regexid, $elements, $r) : void {
            if ($d === null) return; // Avoid fallback hell
            $elements = array_values($elements);
            $element = $elements[$d] ?? null;
            if (!isset($element)) {
                new SubmitBlockSession($this, function(Item $item) use ($regexid, $r) : void {
                    if ($item->getId() === Item::AIR) {
                        $this->editRandomElements($regexid);
                        return;
                    }
                    if ($item->getBlock()->getId() === Item::AIR) {
                        $form = new ModalForm(function (Player $p, bool $d) use ($regexid) : void {
                            $this->editRandomElements($regexid);
                        });
                        $form->setTitle(TF::BOLD . TF::DARK_RED . 'Error');
                        $form->setContent(TF::GOLD . 'Submitted item must be a valid block item!');
                        $this->getPlayer()->sendForm($form);
                    }
                    $r->setElementChance($item->getId(), $item->getDamage(), $item->getCount());
                    $this->editRandomElement($item->getId(), $item->getDamage());
                });
                return;
            }
            $this->editRandomElement($regexid, (int)$element[0], (int)$element[1]);
        });
        foreach ($elements as $element => $chance) {
            $element = explode(':', $element);
            $item = Item::get((int)$element[0], (int)$element[1]);
            $form->addButton(TF::DARK_AQUA . $item->getVanillaName() . "\n" . TF::BLUE . '(' . $element[0] . ':' . $element[1] . ') Chance: ' . $chance);
        }
        $form->addButton(TF::BOLD . TF::DARK_GREEN . 'Add Element');
        $this->getPlayer()->sendForm($form);
    }

    public function editRandomElement(int $regexid, int $id, int $meta = 0) : void {
        $r = $this->getIsland()->getRandomById($regexid);
        $form = new CustomForm(function (Player $p, array $d = null) use ($regexid, $id, $meta, $r) : void {
            if ($d === null) {
                $this->editRandomElements($regexid);
                return;
            }
            $r->setElementChance($id, $meta, 0); // Reset element chance or element will be duplicated if the ID or meta has changed from form
            $id = (int)$d[1];
            $meta = (int)$d[2];
            $r->setElementChance($id, $meta, $d[3]);
            $this->editRandomElement($regexid, $id, $meta);
        });
        $form->setTitle(TF::BOLD . TF::DARK_AQUA . 'Edit Element');
        $form->addInput(TF::AQUA . 'ID', (string)$id, (string)$id);
        $form->addInput(TF::AQUA . 'Meta', (string)$meta, (string)$meta);
        $chance = $r->getElementChance($id, $meta);
        $form->addInput(TF::BOLD . TF::GOLD . 'Chance' . TF::YELLOW . TF::ITALIC . ' (' .

            $chance . ' / ' . ($totalchanceNonZero = ($totalchance = $r->getTotalChance()) == 0 ? (int)$chance : $totalchance) . ', ' . round((int)$chance / $totalchanceNonZero * 100, 2) . '%%)',

            (string)$chance, (string)$chance);
        $this->getPlayer()->sendForm($form);
    }

    public function editRandomLabel(int $regexid) : void {
        $form = new CustomForm(function (Player $p, array $d = null) use ($regexid) : void {
            if ($d === null) {
                $this->editRandomElements($regexid);
                return;
            }
            $this->getIsland()->setRandomLabel($regexid, (string)$d[0]);
        });
        $label = (string)$this->getIsland()->getRandomLabel($regexid);
        $form->addInput(TF::BOLD . TF::GOLD . 'Label', $label, $label);
        $this->getPlayer()->sendForm($form);
    }

    public function editRandomSymbolic(int $regexid) : void {
        new SubmitBlockSession($this, function (Item $item) use ($regexid) : void {
            if ($item->getId() === Item::AIR) {
                $this->editRandomElements($regexid);
                return;
            }
            if ($item->getBlock()->getId() === Item::AIR) {
                $form = new ModalForm(function (Player $p, bool $d) use ($regexid) : void {
                    $this->editRandomElements($regexid);
                });
                $form->setTitle(TF::BOLD . TF::DARK_RED . 'Error');
                $form->setContent(TF::GOLD . 'Submitted item must be a valid block item!');
                $this->getPlayer()->sendForm($form);
            }
            $this->getIsland()->setRandomSymbolic($regexid, $item->getId(), $item->getDamage());
        });
    }
}