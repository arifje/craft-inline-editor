<?php

namespace arifje\inlineeditor\controllers;

use arifje\inlineeditor\Plugin;
use arifje\inlineeditor\services\Editor;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\fields\Assets as AssetsField;
use craft\helpers\Assets as AssetsHelper;
use craft\web\Controller;
use craft\web\UploadedFile;
use Throwable;
use yii\base\InvalidArgumentException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Frontend actions. Every action:
 *  - requires a logged-in user (allowAnonymous = false → Craft enforces the login),
 *  - requires the user to be allowed to use the inline editor (plugin settings),
 *  - is CSRF-protected for POST requests by craft\web\Controller,
 *  - and authorises the specific element (and asset/volume) through Craft's own
 *    permission checks, so a client-supplied element or field ID can never reach
 *    content the user may not save.
 */
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
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireEditorAccess();

        $request = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $siteId = (int)($request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);
        $handle = (string)$request->getRequiredBodyParam('field');

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
        if (!$element) {
            throw new BadRequestHttpException("Element {$elementId} not found.");
        }
        $this->requireCanSave($element);

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
        } catch (InvalidArgumentException | BadRequestHttpException $e) {
            return $this->asJson([
                'success' => false,
                'error' => $e->getMessage(),
            ])->setStatusCode(400);
        } catch (Throwable $e) {
            Craft::error('Inline Editor: saving field "' . $handle . '" on element ' . $elementId . ' failed: ' . $e->getMessage(), __METHOD__);
            return $this->asJson([
                'success' => false,
                'error' => 'The value could not be saved. Check the Craft logs for details.',
            ])->setStatusCode(500);
        }

        if (!$saved) {
            return $this->asJson([
                'success' => false,
                'error' => 'Element failed validation.',
                'errors' => $element->getErrors(),
            ])->setStatusCode(422);
        }

        $values = $editor->getSavedValues($element, $handle);

        return $this->asJson([
            'success' => true,
            'elementId' => $element->id,
            'field' => $handle,
            'value' => $values['value'],
            'rawValue' => $values['rawValue'],
        ]);
    }

    /**
     * Replace or clear an Assets field value.
     *
     * POST actions/inline-editor/default/replace-asset
     *   elementId (int), siteId (int), field (string), removeAssetId (int)
     *   file (uploaded file)  — replaces the targeted asset with the uploaded file
     *   clear=1               — removes the targeted asset instead
     */
    public function actionReplaceAsset(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requireEditorAccess();

        $request   = Craft::$app->getRequest();
        $elementId = (int)$request->getRequiredBodyParam('elementId');
        $siteId    = (int)($request->getBodyParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);
        $handle    = (string)$request->getRequiredBodyParam('field');
        $clear     = (bool)$request->getBodyParam('clear', false);

        $element = Craft::$app->getElements()->getElementById($elementId, null, $siteId);
        if (!$element) {
            throw new BadRequestHttpException("Element {$elementId} not found.");
        }
        $this->requireCanSave($element);

        $field = $element->getFieldLayout()?->getFieldByHandle($handle);
        if (!($field instanceof AssetsField)) {
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

        $elementsService = Craft::$app->getElements();

        // ── Clear ──────────────────────────────────────────────────────────────
        if ($clear) {
            // $removeId is guaranteed non-zero here (guard above).
            $assetToDelete = $elementsService->getElementById($removeId, Asset::class);
            if ($assetToDelete && !$elementsService->canDelete($assetToDelete)) {
                throw new ForbiddenHttpException('You are not allowed to delete this asset.');
            }

            $newIds = array_values(array_diff($existingIds, [$removeId]));
            $element->setFieldValue($handle, $newIds);
            if (!$elementsService->saveElement($element)) {
                return $this->asJson([
                    'success' => false,
                    'error'   => 'Could not save element.',
                    'errors'  => $element->getErrors(),
                ])->setStatusCode(422);
            }

            // Delete the asset element (and its file) after unlinking it.
            if ($assetToDelete) {
                $elementsService->deleteElement($assetToDelete);
            }

            return $this->asJson(['success' => true]);
        }

        // ── Replace ────────────────────────────────────────────────────────────
        $uploadedFile = UploadedFile::getInstanceByName('file');
        if ($uploadedFile === null) {
            return $this->asJson([
                'success' => false,
                'error'   => 'No file provided.',
            ])->setStatusCode(400);
        }

        $folderId = Plugin::getInstance()->getEditor()->resolveUploadFolder($field, $element);
        $folder = $folderId !== null ? Craft::$app->getAssets()->getFolderById($folderId) : null;
        if ($folder === null) {
            return $this->asJson([
                'success' => false,
                'error'   => 'Could not resolve the upload folder. Check the "Upload Location" setting on the Assets field.',
            ])->setStatusCode(500);
        }

        $asset = new Asset();
        $asset->tempFilePath            = $uploadedFile->tempName;
        $asset->filename                = AssetsHelper::prepareAssetName($uploadedFile->name);
        $asset->newFolderId             = $folder->id;
        $asset->volumeId                = $folder->volumeId;
        $asset->uploaderId              = Craft::$app->getUser()->getId();
        $asset->avoidFilenameConflicts  = true;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        // Same check Craft's own upload action performs (saveAssets:<volume>).
        if (!$elementsService->canSave($asset)) {
            throw new ForbiddenHttpException('You are not allowed to upload assets to this volume.');
        }

        if (!$elementsService->saveElement($asset)) {
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
        if (!$elementsService->saveElement($element)) {
            return $this->asJson([
                'success' => false,
                'error'   => 'Could not save element.',
                'errors'  => $element->getErrors(),
            ])->setStatusCode(422);
        }

        // Re-fetch the asset so its path/folder are fully hydrated from the
        // database; calling getUrl() on the just-saved in-memory object can
        // return a URL without the subfolder component.
        $freshAsset = $elementsService->getElementById($asset->id, Asset::class);

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
        $this->requireEditorAccess();

        $request = Craft::$app->getRequest();
        $groupId = (int)$request->getRequiredParam('groupId');
        $search = trim((string)$request->getParam('search', ''));
        $siteId = (int)($request->getParam('siteId') ?: Craft::$app->getSites()->getCurrentSite()->id);

        $tags = Plugin::getInstance()->getEditor()->searchTags($groupId, $search, $siteId);

        return $this->asJson(['tags' => $tags]);
    }

    /**
     * @throws ForbiddenHttpException when the current user may not use the inline editor.
     */
    private function requireEditorAccess(): void
    {
        if (!Plugin::getInstance()->canCurrentUserEdit()) {
            throw new ForbiddenHttpException('You do not have permission to use the inline editor.');
        }
    }

    /**
     * Element-level authorisation through Craft (section permissions, site
     * permissions, EVENT_AUTHORIZE_SAVE, …) — the same check the control panel makes.
     *
     * @throws ForbiddenHttpException
     */
    private function requireCanSave(ElementInterface $element): void
    {
        if (!Craft::$app->getElements()->canSave($element)) {
            throw new ForbiddenHttpException('You are not allowed to save this element.');
        }
    }
}
