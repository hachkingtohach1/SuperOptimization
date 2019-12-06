<?php

declare(strict_types=1);

namespace DragoVN\event;

use pocketmine\event\Event;
use pocketmine\event\Cancellable;
use DragoVN\main;

class NormalRestartEvent extends Event implements Cancellable{

    protected $plugin;

    public function __construct(main $plugin){
        $this->plugin = $plugin;
    }

    public function setDelay(int $mins){
        $this->plugin->delay = $mins;
    }
}
