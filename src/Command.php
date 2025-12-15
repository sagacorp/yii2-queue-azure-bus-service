<?php

namespace sagacorp\queue\azure;

use yii\queue\cli\Command as CliCommand;

/**
 * @property Queue $queue
 */
class Command extends CliCommand
{
    public function actionListen(): void
    {
        $this->queue->run(true);
    }

    protected function isWorkerAction($actionID): bool
    {
        return 'listen' === $actionID;
    }
}
