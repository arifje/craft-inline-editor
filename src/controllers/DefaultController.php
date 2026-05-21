<?php

namespace arifje\inlineeditor\controllers;

use arifje\inlineeditor\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class DefaultController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    /**
     * Save an inline edit. Expects JSON or form POST:
     *   elementId (int), siteId (int), field (string), value (string)
     *
     * Admin-only. CSRF is enforced by Craft's base controller.
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Craft::$app->getUser()->getIsAdmin()) {
            throw new ForbiddenHttpException('Inline editing is restricted to administrators.');
        }

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $siteId = (int)($request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);
        $handle = (string)$request->getRequiredBodyParam('field');
        $value = $request->getBodyParam('value', '');

        if (!is_string($value)) {
            throw new BadRequestHttpException('Field value must be a string.');
        }

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
        if (!$element) {
            throw new BadRequestHttpException("Element {$elementId} not found.");
        }

        $editor = Plugin::getInstance()->getEditor();

        try {
            $saved = $editor->save($element, $handle, $value);
        } catch (\Throwable $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ])->setStatusCode(400);
        }

        if (!$saved) {
            return $this->asJson([
                'success' => false,
                'error' => 'Element failed validation.',
                'errors' => $element->getErrors(),
            ])->setStatusCode(422);
        }

        return $this->asJson([
            'success' => true,
            'elementId' => $element->id,
            'field' => $handle,
            'value' => $handle === 'title' ? $element->title : (string)$element->getFieldValue($handle),
        ]);
    }
}
