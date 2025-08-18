<?php

namespace wock\announcer\Commands;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\plugin\PluginOwned;
use pocketmine\utils\TextFormat;
use wock\announcer\AutoAnnouncer;

class AutoAnnounceCommand extends Command implements PluginOwned {

    /** @var AutoAnnouncer */
    private AutoAnnouncer $plugin;

    public function __construct(AutoAnnouncer $plugin) {
        parent::__construct("autoannouncer", "Reload the AutoAnnouncer configuration", "/autoannouncer", ["aa"]);
        $this->setPermission("autoannouncer.reload");
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool
    {
        if (!$sender->hasPermission("autoannouncer.reload")) {
            $sender->sendMessage(TextFormat::RED . "You do not have sufficient permission to use this command!");
            return false;
        }

        $this->plugin->reloadConfig();
        $sender->sendMessage(TextFormat::GREEN . "Successfully reloaded configuration.");
        return true;
    }

    public function getOwningPlugin(): AutoAnnouncer
    {
        return $this->plugin;
    }
}

