<?php

namespace wock\announcer;

use pocketmine\entity\Entity;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as C;
use wock\announcer\Commands\AutoAnnounceCommand;
use wock\announcer\Tasks\AnnounceTask;

class AutoAnnouncer extends PluginBase {

    private const CONFIG_VERSION = "2.1.0";

    /** @var string[][]|array messages from config */
    public array $messages = [];

    private int $currentIndex = 0;
    private string $prefix = "[AA] ";
    private bool $usePrefix = true;
    private bool $enableSound = true;
    private ?string $soundName = null;

    /** @var array<int, array{message:string[], enable_sound:bool, sound_name:string|null}> */
    private array $runtimeMessages = [];

    /** @var array<int, array{message:string[], remaining_cycles:int, enable_sound:bool, sound_name:string|null}> */
    private array $tempMessages = [];

    private Config $runtimeFile;
    private Config $tempFile;

    public function onEnable(): void {
        @mkdir($this->getDataFolder());
        $this->saveDefaultConfig();
        $config = $this->getConfig();

        $currentVersion = $config->get("version");
        if ($currentVersion === null || $currentVersion !== self::CONFIG_VERSION) {
            $this->getLogger()->info("Updating configuration to version " . self::CONFIG_VERSION);
            $this->backupExistingConfig();
            $this->saveResource("config.yml", true);
            $this->reloadConfig();
            $config = $this->getConfig();
        }

        $this->messages = $config->get("messages", []);
        $this->prefix = (string)$config->getNested("settings.prefix", "[AA] ");
        $this->usePrefix = (bool)$config->getNested("settings.use-prefix", true);
        $interval = (int)$config->getNested("settings.interval", 60);
        $this->soundName = $config->getNested("settings.sound") ?? null;
        $this->enableSound = (bool)$config->getNested("settings.enable-sound", true);

        $this->runtimeFile = new Config($this->getDataFolder() . "runtime_announcements.json", Config::JSON);
        $this->runtimeMessages = $this->normalizeRuntime($this->runtimeFile->get("runtime", []));

        $this->tempFile = new Config($this->getDataFolder() . "temp_announcements.json", Config::JSON);
        $this->tempMessages = $this->normalizeTemp($this->tempFile->get("temporary", []));

        $this->getScheduler()->scheduleRepeatingTask(new AnnounceTask($this), max(1, $interval) * 20);
        $this->getServer()->getCommandMap()->register("autoannouncer", new AutoAnnounceCommand($this));
    }

    private function getCombinedMessages(): array {
        $combined = [];

        foreach ($this->messages as $i => $entry) {
            $lines = $entry['message'] ?? $entry['text'] ?? [];
            if (!empty($lines)) {
                $combined[] = [
                    'src' => 'config',
                    'idx' => $i,
                    'message' => $lines,
                    'enable_sound' => $this->enableSound,
                    'sound_name' => $entry['sound'] ?? $this->soundName
                ];
            }
        }
        foreach ($this->runtimeMessages as $i => $entry) {
            $combined[] = ['src'=>'runtime','idx'=>$i,'message'=>$entry['message'],'enable_sound'=>$entry['enable_sound'],'sound_name'=>$entry['sound_name']];
        }
        foreach ($this->tempMessages as $i => $entry) {
            $combined[] = ['src'=>'temp','idx'=>$i,'message'=>$entry['message'],'enable_sound'=>$entry['enable_sound'],'sound_name'=>$entry['sound_name']];
        }
        return $combined;
    }

    public function broadcastNextMessage(): void {
        $combined = $this->getCombinedMessages();
        if (count($combined) === 0) return;

        $config = $this->getConfig();
        $random = (bool)$config->getNested("settings.random", false);

        if ($random) $choice = $combined[mt_rand(0,count($combined)-1)];
        else {
            if ($this->currentIndex >= count($combined)) $this->currentIndex = 0;
            $choice = $combined[$this->currentIndex];
            $this->currentIndex = ($this->currentIndex+1) % max(1,count($combined));
        }

        $this->broadcastLines($choice['message'], $choice['enable_sound'], $choice['sound_name']);

        if ($choice['src'] === 'temp') {
            $idx = $choice['idx'];
            if (isset($this->tempMessages[$idx])) {
                $this->tempMessages[$idx]['remaining_cycles']--;
                if ($this->tempMessages[$idx]['remaining_cycles'] <= 0) {
                    unset($this->tempMessages[$idx]);
                    $this->tempMessages = array_values($this->tempMessages);
                }
                $this->saveTemporary();
            }
        }
    }

    private function broadcastLines(array $lines, bool $enableSound, ?string $soundName): void {
        $firstLine = true;
        foreach ($lines as $line) {
            $formattedLine = C::colorize($line);
            foreach ($this->getServer()->getOnlinePlayers() as $player) {
                $msg = ($firstLine && $this->usePrefix) ? C::colorize($this->prefix).$formattedLine : $formattedLine;
                $player->sendMessage($msg);
                if ($enableSound && $soundName) $this->playSound($player, $soundName);
            }
            $firstLine = false;
        }
    }

    public function playSound(Entity $player, string $sound, int $volume=1, int $pitch=1, int $radius=5): void {
        foreach ($player->getWorld()->getNearbyEntities($player->getBoundingBox()->expandedCopy($radius,$radius,$radius)) as $p) {
            if ($p instanceof Player && $p->isOnline()) {
                $spk = new PlaySoundPacket();
                $loc = $p->getLocation();
                $spk->soundName = $sound;
                $spk->x = $loc->getX();
                $spk->y = $loc->getY();
                $spk->z = $loc->getZ();
                $spk->volume = $volume;
                $spk->pitch = $pitch;
                $p->getNetworkSession()->sendDataPacket($spk);
            }
        }
    }

    public function openAddForm(Player $player): void {
        if (!class_exists(\jojoe77777\FormAPI\CustomForm::class)) {
            $player->sendMessage(C::RED."[AutoAnnouncer] FormAPI not found.");
            return;
        }

        $form = new \jojoe77777\FormAPI\CustomForm(function(Player $p, ?array $data){
            if($data===null) return;

            $rawText = (string)($data[0] ?? "");
            $permanent = (bool)($data[1] ?? false);
            $cyclesInput = trim((string)($data[2] ?? "0")); // text input
            $cycles = is_numeric($cyclesInput) ? (int)$cyclesInput : 0;
            $enableSound = (bool)($data[3] ?? true);
            $customSound = trim((string)($data[4] ?? ""));

            $lines = array_values(array_filter(array_map(static fn($s) => trim($s), preg_split('/\\\\n/', $rawText)), static fn($s) => $s !== ""));
            if(empty($lines)){
                $p->sendMessage(C::RED."[AutoAnnouncer] Enter at least one line.");
                return;
            }

            if($permanent){
                $this->addRuntimePermanent($lines, $enableSound, $customSound);
                $p->sendMessage(C::GREEN."[AutoAnnouncer] Added permanent announcement.");
            } else {
                if($cycles <= 0){
                    $p->sendMessage(C::RED."[AutoAnnouncer] Set cycles > 0 for temporary.");
                    return;
                }
                $this->addTemporary($lines, $cycles, $enableSound, $customSound);
                $p->sendMessage(C::GREEN."[AutoAnnouncer] Added temporary announcement (cycles: {$cycles}).");
            }
        });

        $form->setTitle("AutoAnnouncer — Add");
        $form->addInput("Announcement text (use \\n for new lines)", "Line 1\\nLine 2");
        $form->addToggle("Permanent?", false);
        $form->addInput("Cycles (if temporary)", "10"); // changed from slider to text input
        $form->addToggle("Enable sound?", true);
        $form->addInput("Custom sound (optional)", "{$this->soundName}");
        $player->sendForm($form);
    }

    /**
     * Opens the edit form to modify an existing announcement.
     */
    public function openEditForm(Player $player): void {
        if (!class_exists(\jojoe77777\FormAPI\CustomForm::class)) {
            $player->sendMessage(C::RED."[AutoAnnouncer] FormAPI not found.");
            return;
        }

        $allMessages = array_merge(
            array_map(fn($m)=>["type"=>"Permanent","message"=>$m['message'],"enable_sound"=>$m['enable_sound'],"sound_name"=>$m['sound_name']], $this->runtimeMessages),
            array_map(fn($m)=>["type"=>"Temporary ({$m['remaining_cycles']} cycles)","message"=>$m['message'],"enable_sound"=>$m['enable_sound'],"sound_name"=>$m['sound_name']], $this->tempMessages)
        );

        if (empty($allMessages)) {
            $player->sendMessage(C::YELLOW."[AutoAnnouncer] No announcements to edit.");
            return;
        }

        $form = new \jojoe77777\FormAPI\CustomForm(function(Player $p, ?array $data) use ($allMessages) {
            if ($data===null) return;
            $idx = (int)($data[0] ?? 0);
            $rawText = (string)($data[1] ?? "");
            $enableSound = (bool)($data[2] ?? true);
            $customSound = trim((string)($data[3] ?? ""));

            $lines = array_values(array_filter(array_map(static fn($s)=>trim($s), preg_split('/\\\\n/', $rawText)), static fn($s)=>$s!==""));
            if (empty($lines)) { $p->sendMessage(C::RED."[AutoAnnouncer] Enter at least one line."); return; }

            if ($idx < count($this->runtimeMessages)) {
                $this->runtimeMessages[$idx]['message']=$lines;
                $this->runtimeMessages[$idx]['enable_sound']=$enableSound;
                $this->runtimeMessages[$idx]['sound_name']=$customSound;
                $this->saveRuntime();
            } else {
                $tmpIdx = $idx - count($this->runtimeMessages);
                if(isset($this->tempMessages[$tmpIdx])){
                    $this->tempMessages[$tmpIdx]['message']=$lines;
                    $this->tempMessages[$tmpIdx]['enable_sound']=$enableSound;
                    $this->tempMessages[$tmpIdx]['sound_name']=$customSound;
                    $this->saveTemporary();
                }
            }
            $p->sendMessage(C::GREEN."[AutoAnnouncer] Announcement updated!");
        });

        $form->setTitle("AutoAnnouncer — Edit");
        $options = [];
        foreach ($allMessages as $msg) {
            $options[] = $msg['type']." — ".implode(" | ", array_slice($msg['message'],0,3)).(count($msg['message'])>3 ? "..." : "");
        }
        $form->addDropdown("Select announcement", $options);
        $form->addInput("Edit text (use \\n for new lines)");
        $form->addToggle("Enable sound?", true);
        $form->addInput("Custom sound (optional)", "");
        $player->sendForm($form);
    }

    /**
     * Opens the delete form to remove an announcement.
     */
    public function openDeleteForm(Player $player): void {
        if (!class_exists(\jojoe77777\FormAPI\SimpleForm::class)) {
            $player->sendMessage(C::RED."[AutoAnnouncer] FormAPI not found.");
            return;
        }

        $allMessages = array_merge(
            array_map(fn($m)=>["type"=>"Permanent","message"=>$m['message']], $this->runtimeMessages),
            array_map(fn($m)=>["type"=>"Temporary ({$m['remaining_cycles']} cycles)","message"=>$m['message']], $this->tempMessages)
        );

        if (empty($allMessages)) {
            $player->sendMessage(C::YELLOW."[AutoAnnouncer] No announcements to delete.");
            return;
        }

        $form = new \jojoe77777\FormAPI\SimpleForm(function(Player $p, ?int $data) {
            if ($data === null) return;
            if ($data < count($this->runtimeMessages)) {
                unset($this->runtimeMessages[$data]);
                $this->runtimeMessages = array_values($this->runtimeMessages);
                $this->saveRuntime();
            } else {
                $tmpIdx = $data - count($this->runtimeMessages);
                if(isset($this->tempMessages[$tmpIdx])){
                    unset($this->tempMessages[$tmpIdx]);
                    $this->tempMessages = array_values($this->tempMessages);
                    $this->saveTemporary();
                }
            }
            $p->sendMessage(C::GREEN."[AutoAnnouncer] Announcement deleted.");
        });

        $form->setTitle("AutoAnnouncer — Delete");
        foreach ($allMessages as $msg) {
            $form->addButton($msg['type']." — ".implode(" | ", array_slice($msg['message'],0,3)).(count($msg['message'])>3 ? "..." : ""));
        }
        $player->sendForm($form);
    }

    public function addRuntimePermanent(array $lines, bool $enableSound=true, ?string $soundName=null): void {
        $this->runtimeMessages[] = ["message"=>$lines,"enable_sound"=>$enableSound,"sound_name"=>$soundName];
        $this->saveRuntime();
    }

    public function addTemporary(array $lines, int $cycles, bool $enableSound=true, ?string $soundName=null): void {
        $this->tempMessages[] = ["message"=>$lines,"remaining_cycles"=>max(1,$cycles),"enable_sound"=>$enableSound,"sound_name"=>$soundName];
        $this->saveTemporary();
    }

    private function normalizeRuntime($raw): array {
        $out = [];
        if(is_array($raw)){
            foreach($raw as $entry){
                if(isset($entry['message'])){
                    $out[]=[
                        "message"=>array_values((array)$entry['message']),
                        "enable_sound"=>$entry['enable_sound']??true,
                        "sound_name"=>$entry['sound_name']??null
                    ];
                }
            }
        }
        return $out;
    }

    private function normalizeTemp($raw): array {
        $out=[];
        if(is_array($raw)){
            foreach($raw as $entry){
                if(isset($entry['message'],$entry['remaining_cycles'])){
                    $rc=(int)$entry['remaining_cycles'];
                    if($rc>0 && !empty($entry['message'])){
                        $out[]=[
                            "message"=>array_values((array)$entry['message']),
                            "remaining_cycles"=>$rc,
                            "enable_sound"=>$entry['enable_sound']??true,
                            "sound_name"=>$entry['sound_name']??null
                        ];
                    }
                }
            }
        }
        return $out;
    }

    private function saveRuntime(): void {
        $this->runtimeFile->set("runtime",$this->runtimeMessages);
        $this->runtimeFile->save();
    }

    private function saveTemporary(): void {
        $this->tempFile->set("temporary",$this->tempMessages);
        $this->tempFile->save();
    }

    private function backupExistingConfig(): void {
        $path=$this->getDataFolder()."config.yml";
        if(file_exists($path)) @rename($path,$this->getDataFolder()."old_config.yml");
    }
}
