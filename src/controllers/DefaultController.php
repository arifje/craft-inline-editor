<?php

namespace arifje\inlineeditor\controllers;

use arifje\inlineeditor\Plugin;
use arifje\inlineeditor\services\Editor;
use Craft;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

class DefaultController extends Controller
{
    protected array|int|bool $allowAnonymous = false;

    /**
     * Save an inline edit.
     *
     * For plain-text / URL / CKEditor / title fields expects:
     *   elementId (int), siteId (int), field (string), value (string)
     *
     * For Tags fields expects:
     *   elementId (int), siteId (int), field (string),
     *   tagIds[] (int[]), newTags[] (string[])
     *
     * Admin-only. CSRF is enforced by Craft's base controller.
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Plugin::getInstance()->canCurrentUserEdit()) {
            throw new ForbiddenHttpException('You do not have permission to use the inline editor.');
        }

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $siteId = (int)($request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);
        $handle = (string)$request->getRequiredBodyParam('field');

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
        if (!$element) {
            throw new BadRequestHttpException("Element {$elementId} not found.");
        }

        $editor = Plugin::getInstance()->getEditor();

        try {
            $type = $editor->detectType($element, $handle);

            if ($type === Editor::TYPE_TAGS) {
                $tagIds = array_map('intval', array_filter((array)$request->getBodyParam('tagIds', [])));
                $newTags = array_filter(array_map('strval', (array)$request->getBodyParam('newTags', [])));

                $result = $editor->saveTags($element, $handle, $tagIds, $newTags);

                if (!$result['saved']) {
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
                    'tags' => $result['tags'],
                ]);
            }

            $value = $request->getBodyParam('value', '');
            if (!is_string($value)) {
                throw new BadRequestHttpException('Field value must be a string.');
            }

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

    /**
     * Search tags within a group for the autocomplete dropdown.
     * GET  actions/inline-editor/default/search-tags
     *   ?groupId=1 &search=alb &siteId=1
     */
    public function actionSearchTags(): Response
    {
        $this->requireAcceptsJson();

        if (!Plugin::getInstance()->canCurrentUserEdit()) {
            throw new ForbiddenHttpException();
        }

        $request = Craft::$app->getRequest();
        $groupId = (int)$request->getRequiredParam('groupId');
        $search = trim((string)$request->getParam('search', ''));
        $siteId = (int)($request->getParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);

        $tags = Plugin::getInstance()->getEditor()->searchTags($groupId, $search, $siteId);

        return $this->asJson(['tags' => $tags]);
    }
}
