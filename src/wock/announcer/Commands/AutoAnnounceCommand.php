<?php

namespace wock\announcer\Commands;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\PluginOwned;
use pocketmine\utils\TextFormat as C;
use wock\announcer\AutoAnnouncer;

class AutoAnnounceCommand extends Command implements PluginOwned {

    private AutoAnnouncer $plugin;

    public function __construct(AutoAnnouncer $plugin) {
        parent::__construct("autoannouncer", "Manage AutoAnnouncer", "/autoannouncer <reload|add|edit|delete>", ["aa"]);
        $this->setPermissions(["autoannouncer.reload", "autoannouncer.manage"]);
        $this->plugin = $plugin;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool {
        if (!isset($args[0])) {
            $sender->sendMessage(C::YELLOW . "Usage: /{$commandLabel} <reload|add|edit|delete>");
            return true;
        }

        switch (strtolower($args[0])) {
            case "reload":
                if (!$sender->hasPermission("autoannouncer.reload")) {
                    $sender->sendMessage(C::RED . "You do not have permission to reload!");
                    return true;
                }
                $this->plugin->reloadConfig();
                $sender->sendMessage(C::GREEN . "AutoAnnouncer config reloaded successfully.");
                return true;

            case "add":
                if (!$sender->hasPermission("autoannouncer.manage")) {
                    $sender->sendMessage(C::RED . "You do not have permission to manage announcements!");
                    return true;
                }
                if (!$sender instanceof Player) {
                    $sender->sendMessage(C::RED . "This command can only be used in-game.");
                    return true;
                }
                $this->plugin->openAddForm($sender);
                return true;

            case "edit":
                if (!$sender->hasPermission("autoannouncer.manage")) {
                    $sender->sendMessage(C::RED . "You do not have permission to edit announcements!");
                    return true;
                }
                if (!$sender instanceof Player) {
                    $sender->sendMessage(C::RED . "This command can only be used in-game.");
                    return true;
                }
                $this->plugin->openEditForm($sender);
                return true;

            case "delete":
                if (!$sender->hasPermission("autoannouncer.manage")) {
                    $sender->sendMessage(C::RED . "You do not have permission to delete announcements!");
                    return true;
                }
                if (!$sender instanceof Player) {
                    $sender->sendMessage(C::RED . "This command can only be used in-game.");
                    return true;
                }
                $this->plugin->openDeleteForm($sender);
                return true;

            default:
                $sender->sendMessage(C::YELLOW . "Usage: /{$commandLabel} <reload|add|edit|delete>");
                return true;
        }
    }

    public function getOwningPlugin(): AutoAnnouncer {
        return $this->plugin;
    }
}
