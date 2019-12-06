<?php

declare(strict_types=1);

namespace DragoVN\event;

use pocketmine\event\Event;

class TransferRestartEvent extends Event{

    protected $players;

    public function __construct(array $players){
        $this->players = $players;
    }

    public function getTransferredPlayers() : array{
        return $this->players;
    }
}##
