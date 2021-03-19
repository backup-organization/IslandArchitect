<?php


namespace Clouria\IslandArchitect;


use pocketmine\{
    level\Level,
    level\particle\RedstoneParticle,
    math\AxisAlignedBB,
    math\Vector3,
    scheduler\Task,
    utils\TextFormat as TF};

use Clouria\IslandArchitect\customized\CustomizableClassTrait;

class IslandArchitectPluginTickTask extends Task {
    use CustomizableClassTrait;

    public function onRun(int $ct) : void {
        foreach (IslandArchitect::getInstance()->getSessions() as $s) if ($s->getIsland() !== null) {
            $sb = $s->getPlayer()->getTargetBlock(12);
            $sc = $s->getIsland()->getStartCoord();
            $ec = $s->getIsland()->getEndCoord();

            // Send random generation block popup
            $r = $s->getIsland()->getRandomByVector3($sb);
            if ($r !== null) $s->getPlayer()->sendPopup(TF::YELLOW . 'Random generation block: ' . TF::BOLD . TF::GOLD . $s->getIsland()->getRandomLabel($r));

            // Island chest coord popup
            $chest = $s->getIsland()->getChest();
            if ($chest !== null and $chest->asVector3()->equals($sb->asVector3())) $s->getPlayer()->sendPopup(TF::YELLOW . 'Island chest block' . "\n" . TF::ITALIC . TF::GRAY . '(Click to view or edit contents)');

            // Draw island area outline
            if ($sc !== null and $ec !== null and $s->getPlayer()->getLevel()->getFolderName() === $s->getIsland
                ()->getLevel()) {
                $bb = new AxisAlignedBB(
                    min($sc->getFloorX(), $ec->getFloorX()),
                    min($sc->getFloorY(), $ec->getFloorY()),
                    min($sc->getFloorZ(), $ec->getFloorZ()),
                    max($sc->getFloorX(), $ec->getFloorX()),
                    max($sc->getFloorY(), $ec->getFloorY()),
                    max($sc->getFloorZ(), $ec->getFloorZ())
                );
                $bb->offset(0.5, 0.5, 0.5);
                $bb->expand(0.5, 0.5, 0.5);
                $distance = $s->getPlayer()->getViewDistance();
                $dbb = (new AxisAlignedBB(
                    ($s->getPlayer()->getFloorX() >> 4) - $distance,
                    0,
                    ($s->getPlayer()->getFloorZ() >> 4) - $distance,
                    ($s->getPlayer()->getFloorX() >> 4) + $distance,
                    Level::Y_MAX,
                    ($s->getPlayer()->getFloorZ() >> 4) + $distance
                ))->expand(1, 1, 1);
                for ($x = $bb->minX; $x <= $bb->maxX; ++$x)
                for ($y = $bb->minY; $y <= $bb->maxY; ++$y)
                for ($z = $bb->minZ; $z <= $bb->maxZ; ++$z) {
                    if (!$dbb->isVectorInside(new Vector3((int)$x >> 4, (int)$y >> 4, (int)$z >> 4))) continue;
                    if (
                        $x !== $bb->minX and
                        $y !== $bb->minY and
                        $z !== $bb->minZ and
                        $x !== $bb->maxX and
                        $y !== $bb->maxY and
                        $z !== $bb->maxZ
                    ) continue;
                    $s->getPlayer()->getLevel()->addParticle(new RedstoneParticle(new Vector3($x, $y, $z), 10),
                        [$s->getPlayer()]);
                }
            }
        }
    }

}