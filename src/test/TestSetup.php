<?php
/**
 * @link      https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   https://craftcms.github.io/license/
 */
namespace craft\test;

use Craft;
use craft\test\Craft as CraftTest;
use craft\db\Connection;
use craft\db\Migration;
use craft\helpers\ArrayHelper;
use craft\helpers\MigrationHelper;
use craft\migrations\Install;
use craft\models\Site;
use craft\services\Config;
use craft\web\Application;
use craft\web\UploadedFile;
use Symfony\Component\Yaml\Yaml;
use yii\base\Event;
use yii\base\InvalidArgumentException;
use yii\base\InvalidConfigException;
use yii\db\Exception;

/**
 * Class TestSetup.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @author Global Network Group | Giel Tettelaar <giel@yellowflash.net>
 * @since  3.1
 */
class TestSetup
{
    // Public Methods
    // =========================================================================

    /**
     * Creates a craft object to play with. Ensures the Craft::$app service locator is working.
     *
     * @return mixed
     * @throws InvalidConfigException
     */
    public static function warmCraft()
    {
        $app = self::createTestCraftObjectConfig();
        $app['isInstalled'] = false;

        return Craft::createObject($app);
    }

    /**
     * Taken from the Yii2 Module $i->_after
     */
    public static function tearDownCraft()
    {
        $_SESSION = [];
        $_FILES = [];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_REQUEST = [];

        UploadedFile::reset();
        if (method_exists(Event::class, 'offAll')) {
            Event::offAll();
        }

        Craft::setLogger(null);

        Craft::$app = null;

    }

    /**
     * @param Connection $connection
     * @return bool
     * @throws Exception
     */
    public static function cleanseDb(Connection $connection) : bool
    {
        $tables = $connection->schema->getTableNames();

        foreach ($tables as $table) {
            MigrationHelper::dropTable($table);
        }


        $tables = $connection->schema->getTableNames();

        if ($tables !== []) {
            throw new Exception('Unable to setup test environment.');
        }

        return true;
    }

    /**
     * @param string $class
     * @param array $params
     * @return false|null
     */
    public static function validateAndApplyMigration(string $class, array $params) : bool
    {
        if (!class_exists($class)) {
            throw new InvalidArgumentException('Unable to ');
        }

        $migration = new $class($params);

        if (!$migration instanceof Migration) {
            throw new InvalidArgumentException('Migration class is not an instance of craft\migrations\Migration');
        }

        return $migration->safeUp();
    }

    /**
     * @return array
     */
    public static function createTestCraftObjectConfig() : array
    {
        $_SERVER['REMOTE_ADDR'] = '1.1.1.1';
        $_SERVER['REMOTE_PORT'] = 654321;

        $basePath = dirname(__DIR__, 2);

        $srcPath = $basePath . '/src';
        $vendorPath = CRAFT_VENDOR_PATH;

        Craft::setAlias('@craftunitsupport', $srcPath.'/test');
        Craft::setAlias('@craftunittemplates', $basePath.'/tests/_craft/templates');
        Craft::setAlias('@craftunitfixtures', $basePath.'/tests/fixtures');
        Craft::setAlias('@testsfolder', $basePath.'/tests');
        Craft::setAlias('@crafttestsfolder', $basePath.'/tests/_craft');

        // Load the config
        $config = ArrayHelper::merge(
            [
                'components' => [
                    'config' => [
                        'class' => Config::class,
                        'configDir' => CRAFT_CONFIG_PATH,
                        'appDefaultsDir' => $srcPath . '/config/defaults',
                    ],
                ],
            ],
            require $srcPath . '/config/app.php',
            require $srcPath . '/config/app.web.php'
        );

        // Use app.php from the config dir as well.
        $craftPath = CRAFT_CONFIG_PATH;
        $appConfigPath = $craftPath.'/app.php';

        if (is_file($appConfigPath)) {
            $appConfig = require $appConfigPath;
            $config = ArrayHelper::merge($config, $appConfig);
        }

        $config['vendorPath'] = $vendorPath;

        $config = ArrayHelper::merge($config, [
            'components' => [
                'sites' => [
                    'currentSite' => 'default'
                ]
            ],
        ]);

        return ArrayHelper::merge($config, [
            'class' => Application::class,
            'id' => 'craft-test',
            'basePath' => $srcPath
        ]);
    }

    /**
     * @return bool
     */
    public static function configureCraft() : bool
    {
        define('YII_ENV', 'test');

        $vendorPath = realpath(CRAFT_VENDOR_PATH);

        $configPath = realpath(CRAFT_CONFIG_PATH);
        $contentMigrationsPath = realpath(CRAFT_MIGRATIONS_PATH);
        $storagePath = realpath(CRAFT_STORAGE_PATH);
        $templatesPath = realpath(CRAFT_TEMPLATES_PATH);
        $translationsPath = realpath(CRAFT_TRANSLATIONS_PATH);

        // Log errors to craft/storage/logs/phperrors.log
        ini_set('log_errors', 1);
        ini_set('error_log', $storagePath . '/logs/phperrors.log');

        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        defined('YII_DEBUG') || define('YII_DEBUG', true);
        defined('CRAFT_ENVIRONMENT') || define('CRAFT_ENVIRONMENT', '');

        defined('CURLOPT_TIMEOUT_MS') || define('CURLOPT_TIMEOUT_MS', 155);
        defined('CURLOPT_CONNECTTIMEOUT_MS') || define('CURLOPT_CONNECTTIMEOUT_MS', 156);

        $libPath = dirname(__DIR__, 2) . '/lib';

        $srcPath  = dirname(__DIR__);

        require $libPath . '/yii2/Yii.php';
        require $srcPath.'/Craft.php';

        // Set aliases
        Craft::setAlias('@vendor', $vendorPath);
        Craft::setAlias('@lib', $libPath);
        Craft::setAlias('@craft', $srcPath);
        Craft::setAlias('@config', $configPath);
        Craft::setAlias('@contentMigrations', $contentMigrationsPath);
        Craft::setAlias('@storage', $storagePath);
        Craft::setAlias('@templates', $templatesPath);
        Craft::setAlias('@translations', $translationsPath);

        return true;
    }

    /**
     * @param string $projectConfigFile
     * @param bool $mergeExistingConfig
     */
    public static function setupProjectConfig(string $projectConfigFile, bool $mergeExistingConfig = false)
    {
        if (!is_file($projectConfigFile)) {
            throw new InvalidArgumentException('Project config is not a file');
        }

        $testSuiteProjectConfigPath = CRAFT_CONFIG_PATH.'/project.yaml';
        $contents = file_get_contents($projectConfigFile);
        $arrayContents = Yaml::parse($contents);

        // Do we need to take into account the existing project config file?
        if ($mergeExistingConfig === true && is_file($testSuiteProjectConfigPath)) {
            $existingConfig = file_get_contents($testSuiteProjectConfigPath);
            $arrayContents = array_merge($arrayContents, $existingConfig);
        }

        // Write to the file.
        file_put_contents($testSuiteProjectConfigPath, Yaml::dump($arrayContents));
    }

    /**
     * @param Connection $connection
     * @param CraftTest $craftTestModule
     * @throws Exception
     */
    public static function setupCraftDb(Connection $connection, CraftTest $craftTestModule)
    {
        if ($connection->schema->getTableNames() !== []) {
            throw new Exception('Not allowed to setup the DB if it has not been cleansed');
        }

        $siteConfig = [
            'name' => 'Craft test site',
            'handle' => 'default',
            'hasUrls' => true,
            'baseUrl' => 'https://craftcms.com',
            'language' => 'en-US',
            'primary' => true,
        ];

        // Replace the default site with what is desired by the project config (Currently). If project config is enabled.
        $projectConfig = $craftTestModule->_getConfig('projectConfig');

        if ($projectConfig && isset($projectConfig['file'])) {
            $existingProjectConfig = Yaml::parse(
                file_get_contents($projectConfig['file']) ?: ''
            );

            if ($existingProjectConfig && isset($existingProjectConfig['sites'])) {
                $siteConfig = ArrayHelper::filterByValue(
                    $existingProjectConfig['sites'],
                    'primary',
                    true
                );

                $siteConfig = ArrayHelper::firstValue(
                    $siteConfig
                );

                // This isn't a `settable` property of craft/models/Site
                unset($siteConfig['siteGroup']);
            }
        }

        $site = new Site($siteConfig);

        $migration = new Install([
            'db' => $connection,
            'username' => 'craftcms',
            'password' => 'craftcms2018!!',
            'email' => 'support@craftcms.com',
            'site' => $site,
        ]);

        $migration->safeUp();
    }
}
