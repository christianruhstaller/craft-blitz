<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitz\drivers\deployers;

use Craft;
use craft\db\Table;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use GitWrapper\GitException;
use GitWrapper\GitWorkingCopy;
use GitWrapper\GitWrapper;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\events\RefreshCacheEvent;
use putyourlightson\blitz\helpers\DeployerHelper;
use putyourlightson\blitz\helpers\SiteUriHelper;
use yii\base\ErrorException;
use yii\base\InvalidArgumentException;
use yii\log\Logger;

/**
 * @property mixed $settingsHtml
 */
class GitDeployer extends BaseDeployer
{
    // Properties
    // =========================================================================

    /**
     * @var array
     */
    public $gitRepositories = [];

    /**
     * @var string|null
     */
    public $username;

    /**
     * @var string|null
     */
    public $personalAccessToken;

    /**
     * @var string|null
     */
    public $name;

    /**
     * @var string|null
     */
    public $email;

    /**
     * @var string
     */
    public $defaultCommitMessage = 'Blitz auto commit';

    /**
     * @var string
     */
    public $defaultBranch = 'master';

    /**
     * @var string
     */
    public $defaultRemote = 'origin';

    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('blitz', 'Git Deployer');
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['username', 'personalAccessToken', 'name', 'email'], 'required'],
            [['email'], 'email'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function deployUris(array $siteUris, int $delay = null, callable $setProgressHandler = null)
    {
        $event = new RefreshCacheEvent(['siteUris' => $siteUris]);
        $this->trigger(self::EVENT_BEFORE_DEPLOY, $event);

        if (!$event->isValid) {
            return;
        }

        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            $this->deployUrisWithProgress($siteUris, $setProgressHandler);
        }
        else {
            DeployerHelper::addDriverJob(
                $siteUris,
                [$this, 'deployUrisWithProgress'],
                Craft::t('blitz', 'Deploying pages'),
                $delay
            );
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_DEPLOY)) {
            $this->trigger(self::EVENT_AFTER_DEPLOY, $event);
        }
    }

    /**
     * Deploys site URIs with progress.
     *
     * @param array $siteUris
     * @param callable|null $setProgressHandler
     */
    public function deployUrisWithProgress(array $siteUris, callable $setProgressHandler = null)
    {
        $count = 0;
        $total = 0;
        $label = 'Deploying {count} of {total} pages.';

        $deployGroupedSiteUris = [];
        $groupedSiteUris = SiteUriHelper::getSiteUrisGroupedBySite($siteUris);

        foreach ($groupedSiteUris as $siteId => $siteUris) {
            $siteUid = Db::uidById(Table::SITES, $siteId);

            if ($siteUid === null) {
                continue;
            }

            if (empty($this->gitRepositories[$siteUid]) || empty($this->gitRepositories[$siteUid]['repositoryPath'])) {
                continue;
            }

            $repositoryPath = FileHelper::normalizePath(
                Craft::parseEnv($this->gitRepositories[$siteUid]['repositoryPath'])
            );

            if (FileHelper::isWritable($repositoryPath) === false) {
                continue;
            }

            $deployGroupedSiteUris[$siteUid] = $siteUris;
            $total += count($siteUris);
        }

        if (is_callable($setProgressHandler)) {
            $progressLabel = Craft::t('blitz', $label, ['count' => $count, 'total' => $total]);
            call_user_func($setProgressHandler, $count, $total, $progressLabel);
        }

        foreach ($deployGroupedSiteUris as $siteUid => $siteUris) {
            $repositoryPath = FileHelper::normalizePath(
                Craft::parseEnv($this->gitRepositories[$siteUid]['repositoryPath'])
            );

            foreach ($siteUris as $siteUri) {
                $count++;
                $progressLabel = Craft::t('blitz', $label, ['count' => $count, 'total' => $total]);
                call_user_func($setProgressHandler, $count, $total, $progressLabel);

                $value = Blitz::$plugin->cacheStorage->get($siteUri);

                if (empty($value)) {
                    continue;
                }

                $filePath = FileHelper::normalizePath($repositoryPath.'/'.$siteUri->uri.'/index.html');
                $this->_save($value, $filePath);
            }

            $commitMessage = $this->gitRepositories[$siteUid]['commitMessage'] ?: $this->defaultCommitMessage;
            $commitMessage = addslashes($commitMessage);
            $branch = $this->gitRepositories[$siteUid]['branch'] ?: $this->defaultBranch;
            $remote = $this->gitRepositories[$siteUid]['remote'] ?: $this->defaultRemote;

            // Open repository working copy and add all files to branch
            $gitWrapper = new GitWrapper();
            $git = $gitWrapper->workingCopy($repositoryPath);
            $git->checkout($branch);
            $git->add('*');

            $this->_updateConfig($git, $remote);

            // Check for changes first to avoid an exception being thrown
            if ($git->hasChanges()) {
                $git->commit($commitMessage);
            }

            $git->push($remote);
        }
    }

    /**
     * @inheritdoc
     */
    public function test(): bool
    {
        foreach ($this->gitRepositories as $siteUid => $gitRepository) {
            $repositoryPath = FileHelper::normalizePath(
                Craft::parseEnv($gitRepository['repositoryPath'])
            );

            if (empty($repositoryPath)) {
                continue;
            }

            $branch = $gitRepository['branch'] ?: $this->defaultBranch;
            $remote = $gitRepository['remote'] ?: $this->defaultRemote;

            $gitWrapper = new GitWrapper();
            $git = $gitWrapper->workingCopy($repositoryPath);

            try {
                $git->checkout($branch);

                $this->_updateConfig($git, $remote);
                $git->fetch($remote);
            }
            catch (GitException $e) {
                $site = Craft::$app->getSites()->getSiteByUid($siteUid);

                if ($site !== null) {
                    $this->addError('gitRepositories', $site->name.': '.$e->getMessage());
                }
            }
        }

        return !$this->hasErrors();
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('blitz/_drivers/deployers/git/settings', [
            'deployer' => $this,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Updates the config with credentials.
     *
     * @param GitWorkingCopy $git
     * @param string $remote
     */
    private function _updateConfig(GitWorkingCopy $git, string $remote)
    {
        // Set user in config
        $git->config('user.name', $this->name);
        $git->config('user.email', $this->email);

        // Clear output (important!)
        $git->clearOutput();

        $remoteUrl = $git->getRemote($remote)['push'];

        // Break the URL into parts to reconstruct
        $parts = parse_url($remoteUrl);

        $remoteUrl = ($parts['schema'] ?? 'https').'://'
            .$this->username.':'.$this->personalAccessToken.'@'
            .($parts['host'] ?? '')
            .($parts['path'] ?? '');

        $git->remote('set-url', $remote, $remoteUrl);
    }

    /**
     * Saves a value to a file path.
     *
     * @param string $value
     * @param string $filePath
     */
    private function _save(string $value, string $filePath)
    {
        try {
            FileHelper::writeToFile($filePath, $value);
        }
        catch (ErrorException $e) {
            Craft::getLogger()->log($e->getMessage(), Logger::LEVEL_ERROR, 'blitz');
        }
        catch (InvalidArgumentException $e) {
            Craft::getLogger()->log($e->getMessage(), Logger::LEVEL_ERROR, 'blitz');
        }
    }
}
