<?php

namespace sagacorp\queue\azure;

use yii\queue\cli\Command as CliCommand;

/**
 * @property Queue $queue
 */
class Command extends CliCommand
{
    public function actionListen(?string $queue = null): void
    {
        $this->queue->run(true, $queue);
    }

    protected function isWorkerAction($actionID): bool
    {
        return 'listen' === $actionID;
    }
}
