<?php

namespace teamnovu\publisher\controllers;

use Craft;
use craft\elements\Entry;
use craft\errors\ElementNotFoundException;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use teamnovu\publisher\elements\EntryPublish;
use teamnovu\publisher\Publisher;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Class EntriesController
 *
 * @package teamnovu\publisher\controllers
 */
class EntriesController extends Controller
{
    /**
     * Saves an EntryPublish.
     *
     * @throws ElementNotFoundException
     * @throws \Throwable
     * @throws \yii\db\Exception
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessCp');

        $draftId = $this->request->getBodyParam('publisher_draftId');
        $publishAt = $this->request->getBodyParam('publisher_publishAt');
        $siteId = $this->request->getBodyParam('publisher_sourceSiteId');

        if (!$draftId || !$siteId) {
            throw new BadRequestHttpException('A draft and a site are required.');
        }

        $draft = Entry::find()
            ->draftId($draftId)
            ->siteId($siteId)
            ->status(null)
            ->one();

        if ($draft === null) {
            throw new NotFoundHttpException('Invalid entry draft ID: ' . $draftId);
        }

        $entry = Craft::$app->entries->getEntryById($draft->getCanonicalId(), $siteId);

        if ($entry === null) {
            throw new ElementNotFoundException("No element exists with the ID '{$draft->getCanonicalId()}'");
        }

        // Scheduling a draft applies it to the live entry, so it needs the same permissions as publishing
        // the draft, whether or not the draft is enabled.
        $user = Craft::$app->getUser()->getIdentity();
        $elements = Craft::$app->getElements();

        if (
            !$elements->canView($draft, $user)
            || !$elements->canSave($draft, $user)
            || !$elements->canSave($entry, $user)
        ) {
            throw new ForbiddenHttpException('User is not authorized to schedule this draft.');
        }

        $model = new EntryPublish();
        $model->sourceId = $entry->id;
        $model->publishDraftId = $draft->draftId;
        $model->publishAt = $publishAt ? DateTimeHelper::toDateTime($publishAt, true) : null;
        $model->sourceSiteId = $siteId;

        if (!$model->publishAt) {
            $model->addError('publishAt', Craft::t('publisher-x', 'Enter a valid publish date.'));
        }

        if ($model->hasErrors() || !Publisher::getInstance()->entries->saveEntryPublish($model)) {
            return $this->asFailure(
                implode(' ', $model->getErrorSummary(false)) ?: Craft::t('publisher-x', 'Couldn’t save the publish date.'),
                ['errors' => $model->getErrors()],
                ['publisherEntry' => $model],
            );
        }

        return $this->asSuccess(Craft::t('publisher-x', 'Publish date added.'), [
            'html' => $this->getView()->renderTemplate('publisher-x/_cp/pendingEntries', [
                'entry' => $entry,
            ]),
        ]);
    }


    /**
     * Deletes the EntryPublish.
     *
     * POST only (Craft validates the CSRF token of every POST request), and only for users who may
     * access the control panel and save the scheduled entry and draft.
     *
     * @return Response
     * @throws \Throwable
     * @throws BadRequestHttpException
     * @throws ForbiddenHttpException
     * @throws NotFoundHttpException
     */
    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('accessCp');

        $entriesService = Publisher::getInstance()->entries;
        $publishEntryId = (int)$this->request->getRequiredBodyParam('id');

        $entryPublish = $entriesService->getEntryPublishById($publishEntryId);
        $entry = $entryPublish?->getEntry();

        // Without an entry there is nothing to authorize against.
        if ($entryPublish === null || $entry === null) {
            throw new NotFoundHttpException('EntryPublish not found');
        }

        $draft = $entryPublish->getDraft();
        $user = Craft::$app->getUser()->getIdentity();
        $elements = Craft::$app->getElements();

        if (
            !$elements->canView($entry, $user)
            || !$elements->canSave($entry, $user)
            || ($draft !== null && (!$elements->canView($draft, $user) || !$elements->canSave($draft, $user)))
        ) {
            throw new ForbiddenHttpException('User is not authorized to delete this scheduled publication.');
        }

        $entriesService->deleteEntryPublish($publishEntryId);

        return $this->redirect($draft?->getCpEditUrl() ?? $entry->getCpEditUrl() ?? 'entries');
    }
}
