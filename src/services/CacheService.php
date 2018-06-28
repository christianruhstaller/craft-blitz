<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\helpers\FileHelper;
use craft\helpers\UrlHelper;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\jobs\CacheJob;
use putyourlightson\blitz\models\SettingsModel;
use putyourlightson\blitz\records\ElementCacheRecord;

/**
 *
 * @property string $cacheFolderPath
 * @property bool $isCacheableRequest
 */
class CacheService extends Component
{
    /**
     * @var bool|null
     */
    private $_isCacheableRequest;

    // Public Methods
    // =========================================================================

    /**
     * Returns the cache folder path
     *
     * @return string
     */
    public function getCacheFolderPath(): string
    {
        /** @var SettingsModel $settings */
        $settings = Blitz::$plugin->getSettings();

        if (empty($settings->cacheFolderPath)) {
            return '';
        }

        return FileHelper::normalizePath(Craft::getAlias('@webroot').'/'.$settings->cacheFolderPath);
    }

    /**
     * Returns whether the request is cacheable
     */
    public function getIsCacheableRequest(): bool
    {
        if ($this->_isCacheableRequest !== null) {
            return $this->_isCacheableRequest;
        }

        $this->_isCacheableRequest = $this->_checkIsCacheableRequest();

        return $this->_isCacheableRequest;
    }

    /**
     * Converts URI to file path
     *
     * @param string $uri
     * @return string
     */
    public function uriToFilePath(string $uri): string
    {
        $cacheFolderPath = $this->getCacheFolderPath();

        if ($cacheFolderPath == '') {
            return '';
        }

        $uri = $uri === '__home__' ? '' : $uri;

        return FileHelper::normalizePath($cacheFolderPath.'/'.Craft::$app->getRequest()->getHostName().'/'.$uri.'/index.html');
    }

    /**
     * Adds an element cache to the database
     *
     * @param ElementInterface $element
     * @param string $uri
     */
    public function addElementCache(ElementInterface $element, string $uri)
    {
        /** @var Element $element */
        $values = [
            'elementId' => $element->id,
            'uri' => $uri,
        ];

        $elementCacheRecords = ElementCacheRecord::find()
            ->where($values)
            ->count();

        if ($elementCacheRecords == 0) {
            $elementCacheRecord = new ElementCacheRecord($values);
            $elementCacheRecord->save();
        }
    }

    /**
     * Caches the output to a URI
     *
     * @param string $output
     * @param string $uri
     */
    public function cacheOutput(string $output, string $uri)
    {
        $filePath = $this->uriToFilePath($uri);

        if (!empty($filePath)) {
            $output .= '<!-- Cached by Blitz '.date('c').' -->';
            FileHelper::writeToFile($filePath, $output);
        }
    }

    /**
     * Cache by element
     *
     * @param ElementInterface $element
     */
    public function cacheByElement(ElementInterface $element)
    {
        if (!Blitz::$plugin->getSettings()->cachingEnabled) {
            return;
        }

        $urls = [];

        /** @var Element $element */
        $elementCacheRecords = ElementCacheRecord::find()
            ->select('uri')
            ->where(['elementId' => $element->id])
            ->all();

        /** @var ElementCacheRecord $elementCacheRecord */
        foreach ($elementCacheRecords as $elementCacheRecord) {
            $urls[] = UrlHelper::siteUrl($elementCacheRecord->uri);

            // Delete all records with this URI so we get a fresh element cache table on next cache
            ElementCacheRecord::deleteAll([
                'uri' => $elementCacheRecord->uri,
            ]);
        }

        if (count($urls)) {
            Craft::$app->getQueue()->push(new CacheJob(['urls' => $urls]));
        }
    }

    /**
     * Clears cache by element
     *
     * @param ElementInterface $element
     */
    public function clearCacheByElement(ElementInterface $element)
    {
        /** @var Element $element */
        $elementCacheRecords = ElementCacheRecord::find()
            ->select('uri')
            ->where(['elementId' => $element->id])
            ->all();

        /** @var ElementCacheRecord $elementCacheRecord */
        foreach ($elementCacheRecords as $elementCacheRecord) {
            $filePath = $this->uriToFilePath($elementCacheRecord->uri);

            // Delete file if it exists
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Checks if the request is cacheable
     */
    private function _checkIsCacheableRequest(): bool
    {
        /** @var SettingsModel $settings */
        $settings = Blitz::$plugin->getSettings();

        if (!$settings->cachingEnabled) {
            return false;
        }

        $request = Craft::$app->getRequest();
        $response = Craft::$app->getResponse();
        $uri = $request->getUrl();

        // Check for front-end get request that is not an action request or live preview and returns status 200
        if (!$request->getIsSiteRequest() || !$request->getIsGet() || $request->getIsActionRequest() || $request->getIsLivePreview() || !$response->getIsOk()) {
            return false;
        }

        // Excluded URI patterns take priority
        if (is_array($settings->excludedUriPatterns)) {
            foreach ($settings->excludedUriPatterns as $excludedUriPattern) {
                if ($this->_matchUriPattern($excludedUriPattern[0], $uri)) {
                    return false;
                }
            }
        }

        if (is_array($settings->includedUriPatterns)) {
            foreach ($settings->includedUriPatterns as $includedUriPattern) {
                if ($this->_matchUriPattern($includedUriPattern[0], $uri)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param string $pattern
     * @param string $uri
     * @return bool
     */
    private function _matchUriPattern(string $pattern, string $uri): bool
    {
        if ($pattern == '') {
            return false;
        }

        return preg_match('#'.trim($pattern, '/').'#', $uri);
    }
}
