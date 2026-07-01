<?php

namespace teamnovu\publisher\controllers;

use craft\web\Controller;
use teamnovu\publisher\Publisher;

/**
 * Class ApiController
 *
 * @package teamnovu\publisher\controllers
 */
class ApiController extends Controller
{
    /**
     * @inheritdoc
     */
    protected array|int|bool $allowAnonymous = ['publish'];

    /**
     * Publishes or expires all due entries.
     *
     * @return \yii\web\Response
     * @throws \Throwable
     */
    public function actionPublish()
    {
        $result = Publisher::getInstance()->entries->publishDueEntries();

        return $this->asJson($result);
    }
}
