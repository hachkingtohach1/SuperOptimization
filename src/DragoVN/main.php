<?php

declare(strict_types=1);

namespace DragoVN;

use pocketmine\utils\TextFormat;

use pocketmine\event\Listener;
use pocketmine\plugin\PluginBase;
use pocketmine\Player;
use pocketmine\Server;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;

use pocketmine\utils\Config;
use pocketmine\utils\Internet;
use pocketmine\scheduler\ClosureTask;

use pocketmine\entity\Effect;
use pocketmine\entity\Creature;
use pocketmine\entity\Entity;
use pocketmine\entity\Human;

use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerQuitEvent;

use DragoVN\Utils\CrashDumpReader;
use DragoVN\Utils\DiscordHandler;

use function getopt;
use function in_array;
use function array_map;
use function is_numeric;
use function strtolower;
use function str_replace;

class main extends PluginBase implements Listener {
	
    /** @var int */
    private $interval;
    /** @var int */
    private $seconds;
    /** @var bool */
    private $clearItems;
    /** @var bool */
    private $clearMobs;
    /** @var string[] */
    private $exemptEntities;
    /** @var int[] */
    private $times;
	/** @var string[] */
	public $delay;
	/** @var string[] */
	public $message;
	/** @var string[] */
    public static $serverIp;
	/** @var string[] */
    public static $serverPort;
	/** @var mixed[] */
	private $propertyCache = [];
	/** @var float[] */
	private $breakTimes = [];
	//-----------------------------------------------------------------------------
    public function onEnable(){
	    $this->checkFormAPI();
		$this->checkOldCrashDumps();
	    if (!is_dir($this->getDataFolder())){
            mkdir($this->getDataFolder());
		}	
        $this->saveResource("config.yml");
        $config = new Config($this->getDataFolder() . "config.yml");
        $this->delay = $config->get("delay");
        $this->message = $config->get("message");
        self::$serverIp = $config->get("serverIp");
        self::$serverPort = $config->get("serverPort");
        $this->getScheduler()->scheduleRepeatingTask(new task\CheckTimesTask($this), 20*60);
		//--------------------- 1: Auto Clear Lag, no broadcast! ---------------------//
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
	    $this->getLogger()->info(TextFormat::GREEN."[SuperOptimization] ON!");
	    $config = $this->getConfig()->getAll();        
        $this->interval = $this->seconds = $config["seconds"];
        if(!is_array($config["clear"] ?? [])){
            $this->getLogger()->error(TextFormat::RED."[SuperOptimization][Config error]: clear attribute must an array!");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
		if(!is_numeric($config["seconds"] ?? 300)){
            $this->getLogger()->error(TextFormat::RED."[SuperOptimization][Config error]: seconds attribute must an integer!");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        $clear = $config["clear"] ?? [];
        $this->clearItems = (bool) ($clear["entities-items"] ?? false); //True or False
        $this->clearMobs = (bool) ($clear["entities-mobs"] ?? false); //True or False
		if(!is_array($config["times"] ?? [])){
            $this->getLogger()->error(TextFormat::RED."[SuperOptimization][Config error]: times attribute must an array!");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        if(!is_array($clear["exempt"] ?? [])){
            $this->getLogger()->error(TextFormat::RED."[SuperOptimization][Config error]: clear.exempt attribute must an array!");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        $this->exemptEntities = array_map(function($entity) : string{
            return strtolower((string) $entity);
        }, $clear["exempt"] ?? []);              
        $this->times = $config["times"] ?? [50, 25, 15, 10, 5, 4, 3, 2, 1];
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function($_) : void{
            if(--$this->seconds === 0){
                $entitiesCleared = 0;
                foreach($this->getServer()->getLevels() as $level){
                    foreach($level->getEntities() as $entity){
                        if($this->clearItems && $entity instanceof ItemEntity){
                            $entity->flagForDespawn();
                            ++$entitiesCleared;
                        }else if($this->clearMobs && $entity instanceof Creature && !$entity instanceof Human){
                            if(!in_array(strtolower($entity->getName()), $this->exemptEntities)){
                                $entity->flagForDespawn();
                                ++$entitiesCleared;
				}}}}$this->seconds = $this->interval;
            }else if(in_array($this->seconds, $this->times)){}
        }), 20);
    }//$this->cfg->get('Language')
	
    public function checkFormAPI(){
         $this->formapi = $this->getServer()->getPluginManager()->getPlugin("FormAPI");
         if(is_null($this->formapi)){
               $this->getLogger()->info("§aThe plugin is off because you have not installed FormAPI.");
               $this->getLogger()->info("§cIf you do not install a lot of errors can occur!");
               $this->getServer()->dispatchCommand(new ConsoleCommandSender(), "stop");
	 }
    }
	
	//-----------------------------------------------------------------------------
    public function clearItems2($sender){
        $i = 0;
        foreach($this->getServer()->getLevels() as $level){
            foreach($level->getEntities() as $entity){
                if(!($entity instanceof Creature)){
                    $entity->close();
                    $i++;
                }
            }
        }
        $this->sendMessageClearItems($sender);
    }
	//-----------------------------------------------------------------------------
	public function clearMobs2($sender){
        $i = 0;
        foreach($this->getServer()->getLevels() as $level){
            foreach($level->getEntities() as $entity){
                if($entity instanceof Creature && !($entity instanceof Human)){
                    $entity->close();
                    $i++;
                }
            }
        }
        $this->sendMessageClearMods($sender);
    }
	//-----------------------------------------------------------------------------
	public function onPlayerInteract(PlayerInteractEvent $event) : void{
		if($event->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK){
			$this->breakTimes[$event->getPlayer()->getRawUniqueId()] = floor(microtime(true) * 20);
		}
	}
	
	public function onPlayerQuit(PlayerQuitEvent $event) : void{
		unset($this->breakTimes[$event->getPlayer()->getRawUniqueId()]);
	}
	//-----------------------------------------------------------------------------
	
    public function onDisable(){
	$this->checkNewCrashDump();
	$this->getLogger()->info(TextFormat::RED."[SuperOptimization] OFF!");
	}
	//--------------------- 2: AutoRestart! ---------------------//
	public static function autorestart(Server $server, ?string $serverIp = "default", ?int $serverPort = 19132){
        var_dump((int) $server->getApiVersion());        
        $server->getPluginManager()->callEvent($event = new event\TransferRestartEvent($server->getPluginManager()->getPlugin("SuperOptimization")));
        if($event->isCancelled()){
           return true;}else{
           $transferred = [];
           foreach ($server->getOnlinePlayers() as $players){$transferred[] = $players->getName();$players->transfer($serverIp, $serverPort);
		   }$server->getPluginManager()->callEvent($event = new event\NormalRestartEvent($transferred));
           register_shutdown_function(function () {
              pcntl_exec("./start.sh"); // use for Linux VM or Ubuntu box, ...
		   });
        $server->shutdown();
        }
	}
	//Source from:  https://poggit.pmmp.io/ci/SalmonDE/CrashLogger
	//--------------------- 3: Remove CrashDump! ---------------------//
	private function checkOldCrashDumps(): void{
		$validityDuration = $this->getConfig()->get("validity-duration", 24) * 60 * 60;
		$delete = $this->getConfig()->get("delete-files", false);

		$files = $this->getCrashdumpFiles();
		$this->getLogger()->info("[SuperOptimization] Checking old crash dumps (files: ".count($files).") ...");
		$removed = 0;
		foreach($files as $filePath){
			try{
				$crashDumpReader = new CrashDumpReader($filePath);
				if(!$crashDumpReader->hasRead()){
					continue;
				}
				if($delete === true and time() - $crashDumpReader->getCreationTime() >= $validityDuration){
					unlink($filePath);
					++$removed;
				}
			}catch(\Throwable $e){
				$this->getLogger()->warning("Error during file check of ".basename($filePath).": ".$e->getMessage()." in file ".$e->getFile()." / ".$e->getLine());
				foreach(explode("\n", $e->getTraceAsString()) as $traceString){
					$this->getLogger()->debug('[ERROR] '.$traceString);
				}
			}
		}
		$fileAmount = count($files);
		$percentage = $fileAmount > 0 ? round($removed * 100 / $fileAmount, 2) : 'NAN';
		$message = "Checks finished, Deleted files: ".$removed." (".$percentage."%)";
		if($removed > 0){
			$this->getLogger()->notice($message);
		}else{
			$this->getLogger()->info($message);
		}
	}
	
	private function checkNewCrashDump(): void{
		if($this->getConfig()->get('report-crash', false) !== true){
			return;
		}

		if(trim($this->getConfig()->get('webhook-url', '')) === ''){
			throw new InvalidArgumentException('Webhook url is invalid');
		}

		$this->getLogger()->info('Checking if server crashed ...');
		$files = $this->getCrashdumpFiles();

		$startTime = (int) \pocketmine\START_TIME;
		foreach($files as $filePath){
			try{
				$crashDumpReader = new CrashDumpReader($filePath);

				if(!$crashDumpReader->hasRead() or $crashDumpReader->getCreationTime() < $startTime){
					continue;
				}

				$this->getLogger()->notice('New crash dump found, sending ...');
				$this->reportCrashDump($crashDumpReader);
			}catch(\Throwable $e){
				$this->getLogger()->warning('Error while checking potentially new crash dump "'.basename($filePath).'": '.$e->getMessage().' in file '.$e->getFile().' on line '.$e->getLine());
				foreach(explode("\n", $e->getTraceAsString()) as $traceString){
					$this->getLogger()->debug('[ERROR] '.$traceString);
				}
			}
		}

		$this->getLogger()->info('Checks finished');
	}		

	private function reportCrashDump(CrashDumpReader $crashDumpReader): void{
		if($crashDumpReader->hasRead()){
			(new DiscordHandler($this->getConfig()->get('webhook-url'), $crashDumpReader, $this->getConfig()->get('announce-crash-report', true), $this->getConfig()->get('announce-full-path', false)))->submit();
			$this->getLogger()->debug('Crash dump sent');
		}
	}

	public function getCrashdumpFiles(): array{
		return glob($this->getServer()->getDataPath().'crashdumps/*.log');
	}
	//----------------------------------------------------------------------
	
	public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args):bool
    {
       switch($cmd->getName()){		 
		case "superoptimization":
		   if(!$sender->hasPermission("super.optimization")){
				$sender->sendMessage(TextFormat::RED."You do not have permission to use it!");
				return true;
		   }
           if(!($sender instanceof Player)){
                $sender->sendMessage(TextFormat::RED."This command cannot be used on consoles!");
                return true;
		   }
		$this->menuUI($sender);	
		break;
	   }
	   return true;
	}
	//----------------------------------------------------------------------	
	public function menuUI(Player $sender){
        $api = $this->getServer()->getPluginManager()->getPlugin("FormAPI");
        $form = $api->createSimpleForm(function (Player $sender, $data){
            $result = $data;
            if ($result == null) {
            }
            switch ($result) {
				    case 0:
					break;
					
                    case 1:					
					$this->clearMobs2($sender);	            			
                    break;
                    case 2:					
					$this->clearItems2($sender);	            			
                    break;					
            }
        });
        $form->setTitle("§7[Super Optimization]");
		$form->addButton("§aYou using version: 0.1", 0);
        $form->addButton("Clear Mods", 1);
		$form->addButton("Clear Items", 2);
        $form->sendToPlayer($sender);
		return true;
    }
	//----------------------------------------------------------------------	
	public function sendMessageClearMods($sender){
    $api = $this->getServer()->getPluginManager()->getPlugin("FormAPI");
    $form = $api->createCustomForm(function (Player $sender, $data){});
    $form->setTitle("§7[Super Optimization]");
    $form->addLabel("§c- All §emods §chave been deleted!");
    $form->sendToPlayer($sender);
	}
	//----------------------------------------------------------------------
    public function sendMessageClearItems($sender){
    $api = $this->getServer()->getPluginManager()->getPlugin("FormAPI");
    $form = $api->createCustomForm(function (Player $sender, $data){});
    $form->setTitle("§7[Super Optimization]");
    $form->addLabel("§c- All §eItems §chave been deleted!");
    $form->sendToPlayer($sender);
	}	
}
