<?php

declare(strict_types=1);

namespace DragoVN\task;

use pocketmine\utils\TextFormat;
use pocketmine\scheduler\Task;
use DragoVN\main;

class CheckTimesTask extends Task{
	
    private $plugin;
	
    public function __construct(main $plugin){
        $this->plugin = $plugin;
    }
    public function onRun(int $currentTick) : void{
        if($this->plugin->delay === 0){
            $this->plugin->autorestart($this->plugin->getServer(), main::$serverIp, main::$serverPort);}else{
            if($this->plugin->delay > 5){
               if($this->plugin->delay % 10 === 0){
                  if($this->plugin->message !== ""){
                     $this->plugin->getServer()->broadcastMessage(str_replace("%value%", $this->plugin->delay, TextFormat::DARK_GREEN."[SuperOptimization] ".$this->plugin->message));
				  }
			   }
            }else{
                if($this->plugin->message !== ""){
                    $this->plugin->getServer()->broadcastMessage(str_replace("%value%", $this->plugin->delay, TextFormat::DARK_GREEN."[SuperOptimization] ".$this->plugin->message));
                }
            }
            $this->plugin->delay = $this->plugin->delay - 1;
        }
    }
}
