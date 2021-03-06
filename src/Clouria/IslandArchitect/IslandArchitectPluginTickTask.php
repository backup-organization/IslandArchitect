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


use Clouria\IslandArchitect\customized\CustomizableClassTrait;
use pocketmine\{
    level\Level,
    math\Vector3,
    scheduler\Task,
    math\AxisAlignedBB,
    utils\TextFormat as TF
};

class IslandArchitectPluginTickTask extends Task {
    use CustomizableClassTrait;

    public function onRun(int $ct) : void {
        foreach (IslandArchitect::getInstance()->getSessions() as $s) if (($is = $s->getIsland()) !== null) {
            $sb = $s->getPlayer()->getTargetBlock(12);
            $sc = $is->getStartCoord();
            $ec = $is->getEndCoord();

            // Send random generation block popup
            $r = $is->getRandomByVector3($sb);
            if ($r !== null) $s->getPlayer()->sendPopup(TF::YELLOW . 'Random generation block: ' . TF::BOLD .
                TF::GOLD . $is->getRandomLabel($r));

            // Island chest coord popup
            $chest = $is->getChest();
            if ($chest !== null and $chest->asVector3()->equals($sb->asVector3())) $s->getPlayer()->sendPopup(TF::YELLOW . 'Island chest block'/* . "\n" . TF::ITALIC . TF::GRAY . '(Click to view or edit contents)'*/);

            $distance = $s->getPlayer()->getViewDistance();
            $dbb = (new AxisAlignedBB(
                ($s->getPlayer()->getFloorX() >> 4) - $distance,
                0,
                ($s->getPlayer()->getFloorZ() >> 4) - $distance,
                ($s->getPlayer()->getFloorX() >> 4) + $distance,
                Level::Y_MAX,
                ($s->getPlayer()->getFloorZ() >> 4) + $distance
            ))->expand(1, 1, 1);

            // Island spawn floating text
            $spawn = $is->getSpawn();
            if (
                $spawn === null or $is->getLevel() !== $s->getPlayer()->getLevel()->getFolderName() or
                !$dbb->isVectorInside(new Vector3((int)$spawn->getFloorX() >> 4, (int)$spawn->getFloorY() >> 4, (int)
                    $spawn->getFloorZ() >>
                    4))
            ) $s->hideFloatingText($s::FLOATINGTEXT_SPAWN);
            else $s->showFloatingText($s::FLOATINGTEXT_SPAWN);

            // Island start coord floating text
            if (
                $sc === null or $is->getLevel() !== $s->getPlayer()->getLevel()->getFolderName() or
                !$dbb->isVectorInside(new Vector3((int)$sc->getFloorX() >> 4, (int)$sc->getFloorY() >> 4, (int)
                    $sc->getFloorZ() >>
                    4))
            ) $s->hideFloatingText($s::FLOATINGTEXT_STARTCOORD);
            else $s->showFloatingText($s::FLOATINGTEXT_STARTCOORD);

            // Island end coord floating text
            if (
                $ec === null or $is->getLevel() !== $s->getPlayer()->getLevel()->getFolderName() or
                !$dbb->isVectorInside(new Vector3((int)$ec->getFloorX() >> 4, (int)$ec->getFloorY() >> 4, (int)
                    $ec->getFloorZ() >>
                    4))
            ) $s->hideFloatingText($s::FLOATINGTEXT_ENDCOORD);
            else $s->showFloatingText($s::FLOATINGTEXT_ENDCOORD);
        }
    }

}