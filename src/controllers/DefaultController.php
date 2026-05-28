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
     * Replace or clear an Assets field value.
     *
     * POST actions/inline-editor/default/replace-asset
     *   elementId (int), siteId (int), field (string)
     *   file (uploaded file)  — replaces the field with the uploaded file
     *   clear=1               — empties the field instead
     */
    public function actionReplaceAsset(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        if (!Plugin::getInstance()->canCurrentUserEdit()) {
            throw new ForbiddenHttpException('You do not have permission to use the inline editor.');
        }

        $request   = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $siteId    = (int)($request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);
        $handle    = (string)$request->getRequiredBodyParam('field');
        $clear     = (bool)$request->getBodyParam('clear', false);

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
        if (!$element) {
            throw new BadRequestHttpException("Element {$elementId} not found.");
        }

        $field = $element->getFieldLayout()?->getFieldByHandle($handle);
        if (!($field instanceof \craft\fields\Assets)) {
            return $this->asJson([
                'success' => false,
                'error' => "Field \"{$handle}\" is not an Assets field.",
            ])->setStatusCode(400);
        }

        // Which specific asset this wrapper manages (used in both branches).
        $removeId = (int)$request->getBodyParam('removeAssetId', 0);

        // Fetch current asset IDs once — shared by the safety checks and both branches.
        $existingIds = array_map('intval', $element->getFieldValue($handle)->ids());

        // ── Safety guards ──────────────────────────────────────────────────────
        // Refuse to proceed without an explicit target so a missing/zero
        // removeAssetId can never wipe the whole field or overwrite all assets.
        if (!$removeId) {
            // The only safe exception: uploading into a genuinely empty field
            // (nothing to lose), and only for a replace, not a clear.
            if ($clear || !empty($existingIds)) {
                return $this->asJson([
                    'success' => false,
                    'error'   => 'removeAssetId is required.',
                ])->setStatusCode(400);
            }
        } else {
            // Verify the targeted asset actually belongs to this field on this
            // element — prevents acting on an ID from a different field/entry.
            if (!in_array($removeId, $existingIds, true)) {
                return $this->asJson([
                    'success' => false,
                    'error'   => "Asset {$removeId} is not part of field \"{$handle}\" on element {$elementId}.",
                ])->setStatusCode(400);
            }
        }

        // ── Clear ──────────────────────────────────────────────────────────────
        if ($clear) {
            // $removeId is guaranteed non-zero here (guard above).
            $newIds = array_values(array_diff($existingIds, [$removeId]));
            $element->setFieldValue($handle, $newIds);
            if (!Craft::$app->getElements()->saveElement($element)) {
                return $this->asJson([
                    'success' => false,
                    'error'   => 'Could not save element.',
                    'errors'  => $element->getErrors(),
                ])->setStatusCode(422);
            }

            // Delete the asset element (and its file) after unlinking it.
            $assetToDelete = Craft::$app->getElements()->getElementById($removeId, \craft\elements\Asset::class);
            if ($assetToDelete) {
                Craft::$app->getElements()->deleteElement($assetToDelete);
            }

            return $this->asJson(['success' => true]);
        }

        // ── Replace ────────────────────────────────────────────────────────────
        $uploadedFile = \yii\web\UploadedFile::getInstanceByName('file');
        if ($uploadedFile === null) {
            return $this->asJson([
                'success' => false,
                'error'   => 'No file provided.',
            ])->setStatusCode(400);
        }

        $folderId = Plugin::getInstance()->getEditor()->resolveUploadFolder($field, $element);
        if ($folderId === null) {
            return $this->asJson([
                'success' => false,
                'error'   => 'Could not resolve the upload folder. Check the "Upload Location" setting on the Assets field.',
            ])->setStatusCode(500);
        }

        $asset = new \craft\elements\Asset();
        $asset->tempFilePath            = $uploadedFile->tempName;
        $asset->filename                = \craft\helpers\Assets::prepareAssetName($uploadedFile->name);
        $asset->newFolderId             = $folderId;
        $asset->avoidFilenameConflicts  = true;
        $asset->setScenario(\craft\elements\Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            return $this->asJson([
                'success' => false,
                'error'   => 'Could not save asset.',
                'errors'  => $asset->getErrors(),
            ])->setStatusCode(422);
        }

        // Swap only the managed slot; keep every other asset in the field intact
        // and preserve the original sort order by inserting at the same position.
        // $existingIds was already fetched above — no second DB query needed.
        if ($removeId) {
            $position  = array_search($removeId, $existingIds, true);
            $remaining = array_values(array_diff($existingIds, [$removeId]));

            if ($position !== false) {
                array_splice($remaining, (int)$position, 0, [$asset->id]);
            } else {
                $remaining[] = $asset->id;
            }
            $newIds = $remaining;
        } else {
            // Empty field — safe to just set the new asset (guard above ensures
            // this branch is only reached when $existingIds is empty).
            $newIds = [$asset->id];
        }
        $element->setFieldValue($handle, $newIds);
        if (!Craft::$app->getElements()->saveElement($element)) {
            return $this->asJson([
                'success' => false,
                'error'   => 'Could not save element.',
                'errors'  => $element->getErrors(),
            ])->setStatusCode(422);
        }

        // Re-fetch the asset so its path/folder are fully hydrated from the
        // database; calling getUrl() on the just-saved in-memory object can
        // return a URL without the subfolder component.
        $freshAsset = Craft::$app->getElements()->getElementById($asset->id, \craft\elements\Asset::class);

        return $this->asJson([
            'success' => true,
            'url'     => $freshAsset ? $freshAsset->getUrl() : $asset->getUrl(),
            'id'      => $asset->id,
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
