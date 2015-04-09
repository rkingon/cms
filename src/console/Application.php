<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2015 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\console;

use Craft;
use craft\app\base\ApplicationTrait;
use craft\app\helpers\IOHelper;
use craft\app\helpers\StringHelper;

/**
 * Craft Console Application class
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class Application extends \yii\console\Application
{
	// Traits
	// =========================================================================

	use ApplicationTrait;

	// Public Methods
	// =========================================================================

	/**
	 * Constructor.
	 *
	 * @param array $config
	 */
	public function __construct($config = [])
	{
		Craft::$app = $this;
		parent::__construct($config);
	}

	/**
	 * Initializes the console app by creating the command runner.
	 *
	 * @return null
	 */
	public function init()
	{
		// Set default timezone to UTC
		date_default_timezone_set('UTC');

		// Initialize Cache and Logger right away (order is important)
		$this->getCache();
		$this->processLogTargets();

		// So we can try to translate Yii framework strings
		//$this->coreMessages->attachEventHandler('onMissingTranslation', ['Craft\LocalizationHelper', 'findMissingTranslation']);

		// Set the edition components
		$this->_setEditionComponents();

		// Set the timezone
		$this->_setTimeZone();

		// Call parent::init() before the plugin console command logic so the command runner gets initialized
		parent::init();

		// Load the plugins
		$this->plugins->loadPlugins();

		// Validate some basics on the database configuration file.
		$this->validateDbConfigFile();
	}

	/**
	 * Returns the target application language.
	 *
	 * @return string
	 */
	public function getLanguage()
	{
		return $this->_getLanguage();
	}

	/**
	 * Sets the target application language.
	 *
	 * @param string $language
	 *
	 * @return null
	 */
	public function setLanguage($language)
	{
		$this->_setLanguage($language);
	}

	/**
	 * @inheritdoc
	 */
	public function get($id, $throwException = true)
	{
		if (!$this->has($id, true))
		{
			if (($definition = $this->_getComponentDefinition($id)) !== null)
			{
				$this->set($id, $definition);
			}
		}

		return parent::get($id, $throwException);
	}

	/**
	 * Returns the configuration of the built-in commands.
	 *
	 * @return array The configuration of the built-in commands.
	 */
	public function coreCommands()
	{
		return [
			'help' => 'yii\console\controllers\HelpController',
			'migrate' => 'yii\console\controllers\MigrateController',
			'cache' => 'yii\console\controllers\CacheController',
		];
	}
}