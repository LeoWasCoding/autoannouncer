<?php

namespace wock\announcer;

use pocketmine\entity\Entity;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat as C;
use wock\announcer\Commands\AutoAnnounceCommand;
use wock\announcer\Tasks\AnnounceTask;

class AutoAnnouncer extends PluginBase {

    /** @var string[] */
    public array $messages;
    /** @var int */
    private int $currentIndex;
    /** @var string */
    private string $prefix;
    /** @var bool */
    private bool $usePrefix;

    public function onEnable(): void
    {
        $config = $this->getConfig();

        $currentVersion = $config->get("version");

        if ($currentVersion === null) {
            $this->getLogger()->info("Updating configuration to new format");
            $this->saveOldConfig();
            $this->updateConfig();
            $config = $this->getConfig();
        } elseif ($currentVersion !== "1.0.1") {
            $this->getLogger()->info("Updating configuration to version 1.0.1");
            $this->saveOldConfig();
            $this->updateConfig();
            $config = $this->getConfig();
        }

        $this->messages = $config->get("messages", []);
        $this->currentIndex = 0;
        $this->prefix = $config->getNested("settings.prefix", "[AA] ");
        $this->usePrefix = $config->getNested("settings.use-prefix", true);

        $interval = $config->getNested("settings.interval", 60);

        $this->getScheduler()->scheduleRepeatingTask(new AnnounceTask($this), $interval * 20);
        $this->getServer()->getCommandMap()->register("autoannouncer", new AutoAnnounceCommand($this));
    }

    public function broadcastNextMessage(): void
    {
        $config = $this->getConfig();
        $messagesConfig = $config->get("messages", []);

        if (count($messagesConfig) > 0) {
            if ($config->getNested("settings.random") === true) {
                $randomIndex = mt_rand(0, count($messagesConfig) - 1);
                $messageConfig = $messagesConfig[$randomIndex]['message'] ?? [];
            } elseif ($config->getNested("settings.random") === false) {
                $messageConfig = $messagesConfig[$this->currentIndex]['message'] ?? [];
                $this->currentIndex = ($this->currentIndex + 1) % count($messagesConfig);
            }

            if (empty($messageConfig)) {
                return;
            }

            $firstLine = true;
            foreach ($messageConfig as $messageLine) {
                $formattedLine = C::colorize($messageLine);

                foreach ($this->getServer()->getOnlinePlayers() as $player) {
                    $formattedMessage = ($firstLine && $this->usePrefix) ? C::colorize($this->prefix) . $formattedLine : $formattedLine;
                    $player->sendMessage($formattedMessage);

                    $soundSetting = $this->getConfig()->getNested("settings.sound");
                    if ($soundSetting) {
                        $this->playSound($player, $soundSetting);
                    }
                }

                $firstLine = false;
            }
        }
    }

    /**
     * @param Entity $player
     * @param string $sound
     * @param int $volume
     * @param int $pitch
     * @param int $radius
     */
    public function playSound(Entity $player, string $sound, int $volume = 1, int $pitch = 1, int $radius = 5): void
    {
        foreach ($player->getWorld()->getNearbyEntities($player->getBoundingBox()->expandedCopy($radius, $radius, $radius)) as $p) {
            if ($p instanceof Player) {
                if ($p->isOnline()) {
                    $spk = new PlaySoundPacket();
                    $spk->soundName = $sound;
                    $spk->x = $p->getLocation()->getX();
                    $spk->y = $p->getLocation()->getY();
                    $spk->z = $p->getLocation()->getZ();
                    $spk->volume = $volume;
                    $spk->pitch = $pitch;
                    $p->getNetworkSession()->sendDataPacket($spk);
                }
            }
        }
    }

    private function saveOldConfig(): void
    {
        $oldConfigPath = $this->getDataFolder() . "old_config.yml";
        $this->saveResource("config.yml", false);
        rename($this->getDataFolder() . "config.yml", $oldConfigPath);
    }

    private function updateConfig(): void
    {
        $this->saveDefaultConfig();
    }
}

