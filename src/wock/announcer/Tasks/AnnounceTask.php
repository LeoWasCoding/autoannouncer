<?php

namespace wock\announcer\Tasks;

use pocketmine\scheduler\Task;
use wock\announcer\AutoAnnouncer;

class AnnounceTask extends Task {

    /** @var AutoAnnouncer */
    private AutoAnnouncer $plugin;

    public function __construct(AutoAnnouncer $plugin) {
        $this->plugin = $plugin;
    }

    public function onRun(): void {
        $this->plugin->broadcastNextMessage();
    }
}
