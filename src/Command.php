<?php

namespace sagacorp\queue\azure;

use yii\queue\cli\Command as CliCommand;

/**
 * Class Command
 *
 * @package console\queue\azure
 *
 * @property Queue $queue
 */
class Command extends CliCommand
{
    // region Controllers Actions
    /**
     */
    public function actionListen(?string $queue = null): void
    {
        $this->queue->run(true, queue: $queue);
    }
    // endregion Controllers Actions

    // region Protected Methods
    /**
     * @inheritdoc
     */
    protected function isWorkerAction($actionID): bool
    {
        return $actionID === 'listen';
    }
    // endregion Protected Methods
}
