<?php

namespace sagacorp\queue\azure;

use yii\queue\cli\Command as CliCommand;

/**
 * @property Queue $queue
 */
class Command extends CliCommand
{
    // region Controllers Actions
    public function actionListen(?string $queue = null): void
    {
        $this->queue->run(true, $queue);
    }
    // endregion Controllers Actions

    // region Protected Methods
    protected function isWorkerAction($actionID): bool
    {
        return $actionID === 'listen';
    }
    // endregion Protected Methods
}
