<?php

namespace teamnovu\publisher\console\controllers;

use teamnovu\publisher\Publisher;
use yii\console\Controller;

class PublishController extends Controller
{
    /**
     * Publishes the due entries.
     *
     * @throws \Throwable
     */
    public function actionIndex()
    {
        Publisher::getInstance()->entries->publishDueEntries();

        return;
    }
}
