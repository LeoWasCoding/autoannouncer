<?php

namespace wock\announcer\Tasks;

use pocketmine\scheduler\Task;
use wock\announcer\AutoAnnouncer;

class AnnounceTask extends Task {

    public function __construct(private readonly AutoAnnouncer $plugin) {}

    public function onRun(): void {
        $this->plugin->broadcastNextMessage();
    }
}
