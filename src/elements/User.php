<?php
/**
 * @link http://buildwithcraft.com/
 * @copyright Copyright (c) 2013 Pixel & Tonic, Inc.
 * @license http://buildwithcraft.com/license
 */

namespace craft\app\elements;

use Craft;
use craft\app\base\Element;
use craft\app\base\ElementInterface;
use craft\app\dates\DateInterval;
use craft\app\dates\DateTime;
use craft\app\db\Query;
use craft\app\elements\db\ElementQueryInterface;
use craft\app\elements\db\UserQuery;
use craft\app\enums\AuthError;
use craft\app\enums\UserStatus;
use craft\app\helpers\DateTimeHelper;
use craft\app\helpers\UrlHelper;
use craft\app\models\UserGroup;
use craft\app\records\Session as SessionRecord;
use Exception;
use yii\base\ErrorHandler;
use yii\base\NotSupportedException;
use yii\web\IdentityInterface;

/**
 * User represents a user element.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.0
 */
class User extends Element implements IdentityInterface
{
	// Static
	// =========================================================================

	/**
	 * @inheritdoc
	 */
	public static function classDisplayName()
	{
		return Craft::t('app', 'User');
	}

	/**
	 * @inheritdoc
	 */
	public static function hasContent()
	{
		return true;
	}

	/**
	 * Returns whether this element type can have statuses.
	 *
	 * @return bool
	 */
	public static function hasStatuses()
	{
		return true;
	}

	/**
	 * Returns all of the possible statuses that elements of this type may have.
	 *
	 * @return array|null
	 */
	public static function getStatuses()
	{
		return [
			UserStatus::Active    => Craft::t('app', 'Active'),
			UserStatus::Pending   => Craft::t('app', 'Pending'),
			UserStatus::Locked    => Craft::t('app', 'Locked'),
			UserStatus::Suspended => Craft::t('app', 'Suspended'),
			UserStatus::Archived  => Craft::t('app', 'Archived')
		];
	}

	/**
	 * @inheritdoc
	 *
	 * @return UserQuery The newly created [[UserQuery]] instance.
	 */
	public static function find()
	{
		return new UserQuery(get_called_class());
	}

	/**
	 * @inheritdoc
	 */
	public static function getSources($context = null)
	{
		$sources = [
			'*' => [
				'label' => Craft::t('app', 'All users'),
				'hasThumbs' => true
			]
		];

		if (Craft::$app->getEdition() == Craft::Pro)
		{
			foreach (Craft::$app->userGroups->getAllGroups() as $group)
			{
				$key = 'group:'.$group->id;

				$sources[$key] = [
					'label'     => Craft::t('app', $group->name),
					'criteria'  => ['groupId' => $group->id],
					'hasThumbs' => true
				];
			}
		}

		return $sources;
	}

	/**
	 * @inheritdoc
	 */
	public static function getAvailableActions($source = null)
	{
		$actions = [];

		// Edit
		$editAction = Craft::$app->elements->getAction('Edit');
		$editAction->setParams([
			'label' => Craft::t('app', 'Edit user'),
		]);
		$actions[] = $editAction;

		if (Craft::$app->getUser()->checkPermission('administrateUsers'))
		{
			// Suspend
			$actions[] = 'SuspendUsers';

			// Unsuspend
			$actions[] = 'UnsuspendUsers';
		}

		if (Craft::$app->getUser()->checkPermission('deleteUsers'))
		{
			// Delete
			$actions[] = 'DeleteUsers';
		}

		// Allow plugins to add additional actions
		$allPluginActions = Craft::$app->plugins->call('addUserActions', [$source], true);

		foreach ($allPluginActions as $pluginActions)
		{
			$actions = array_merge($actions, $pluginActions);
		}

		return $actions;
	}

	/**
	 * @inheritdoc
	 */
	public static function defineSearchableAttributes()
	{
		return ['username', 'firstName', 'lastName', 'fullName', 'email'];
	}

	/**
	 * @inheritdoc
	 */
	public static function defineSortableAttributes()
	{
		if (Craft::$app->config->get('useEmailAsUsername'))
		{
			$attributes = [
				'email'         => Craft::t('app', 'Email'),
				'firstName'     => Craft::t('app', 'First Name'),
				'lastName'      => Craft::t('app', 'Last Name'),
				'dateCreated'   => Craft::t('app', 'Join Date'),
				'lastLoginDate' => Craft::t('app', 'Last Login'),
			];
		}
		else
		{
			$attributes = [
				'username'      => Craft::t('app', 'Username'),
				'firstName'     => Craft::t('app', 'First Name'),
				'lastName'      => Craft::t('app', 'Last Name'),
				'email'         => Craft::t('app', 'Email'),
				'dateCreated'   => Craft::t('app', 'Join Date'),
				'lastLoginDate' => Craft::t('app', 'Last Login'),
			];
		}

		// Allow plugins to modify the attributes
		Craft::$app->plugins->call('modifyUserSortableAttributes', [&$attributes]);

		return $attributes;
	}

	/**
	 * @inheritdoc
	 */
	public static function defineTableAttributes($source = null)
	{
		if (Craft::$app->config->get('useEmailAsUsername'))
		{
			$attributes = [
				'email'         => Craft::t('app', 'Email'),
				'firstName'     => Craft::t('app', 'First Name'),
				'lastName'      => Craft::t('app', 'Last Name'),
				'dateCreated'   => Craft::t('app', 'Join Date'),
				'lastLoginDate' => Craft::t('app', 'Last Login'),
			];
		}
		else
		{
			$attributes = [
				'username'      => Craft::t('app', 'Username'),
				'firstName'     => Craft::t('app', 'First Name'),
				'lastName'      => Craft::t('app', 'Last Name'),
				'email'         => Craft::t('app', 'Email'),
				'dateCreated'   => Craft::t('app', 'Join Date'),
				'lastLoginDate' => Craft::t('app', 'Last Login'),
			];
		}

		// Allow plugins to modify the attributes
		Craft::$app->plugins->call('modifyUserTableAttributes', [&$attributes, $source]);

		return $attributes;
	}

	/**
	 * @inheritdoc
	 */
	public static function getTableAttributeHtml(ElementInterface $element, $attribute)
	{
		/** @var User $element */
		// First give plugins a chance to set this
		$pluginAttributeHtml = Craft::$app->plugins->callFirst('getUserTableAttributeHtml', [$element, $attribute], true);

		if ($pluginAttributeHtml !== null)
		{
			return $pluginAttributeHtml;
		}

		switch ($attribute)
		{
			case 'email':
			{
				$email = $element->email;

				if ($email)
				{
					return '<a href="mailto:'.$email.'">'.$email.'</a>';
				}
				else
				{
					return '';
				}
			}

			default:
			{
				return parent::getTableAttributeHtml($element, $attribute);
			}
		}
	}

	/**
	 * @inheritdoc
	 */
	public static function getElementQueryStatusCondition(ElementQueryInterface $query, $status)
	{
		switch ($status)
		{
			case UserStatus::Active:
			{
				return 'users.archived = 0 AND users.suspended = 0 AND users.locked = 0 and users.pending = 0';
			}

			case UserStatus::Pending:
			{
				return 'users.pending = 1';
			}

			case UserStatus::Locked:
			{
				return 'users.locked = 1';
			}

			case UserStatus::Suspended:
			{
				return 'users.suspended = 1';
			}

			case UserStatus::Archived:
			{
				return 'users.archived = 1';
			}
		}
	}

	/**
	 * @inheritdoc
	 */
	public static function getEditorHtml(ElementInterface $element)
	{
		/** @var User $element */
		$html = Craft::$app->templates->render('users/_accountfields', [
			'account'      => $element,
			'isNewAccount' => false,
		]);

		$html .= parent::getEditorHtml($element);

		return $html;
	}

	/**
	 * @inheritdoc Element::saveElement()
	 *
	 * @return bool
	 */
	public static function saveElement(ElementInterface $element, $params)
	{
		/** @var User $element */
		if (isset($params['username']))
		{
			$element->username = $params['username'];
		}

		if (isset($params['firstName']))
		{
			$element->firstName = $params['firstName'];
		}

		if (isset($params['lastName']))
		{
			$element->lastName = $params['lastName'];
		}

		return Craft::$app->users->saveUser($element);
	}

	// Properties
	// =========================================================================

	/**
	 * @var string Username
	 */
	public $username;

	/**
	 * @var string Photo
	 */
	public $photo;

	/**
	 * @var string First name
	 */
	public $firstName;

	/**
	 * @var string Last name
	 */
	public $lastName;

	/**
	 * @var string Email
	 */
	public $email;

	/**
	 * @var string Password
	 */
	public $password;

	/**
	 * @var string Preferred locale
	 */
	public $preferredLocale;

	/**
	 * @var integer Week start day
	 */
	public $weekStartDay = 0;

	/**
	 * @var boolean Admin
	 */
	public $admin = false;

	/**
	 * @var boolean Client
	 */
	public $client = false;

	/**
	 * @var boolean Locked
	 */
	public $locked = false;

	/**
	 * @var boolean Suspended
	 */
	public $suspended = false;

	/**
	 * @var boolean Pending
	 */
	public $pending = false;

	/**
	 * @var \DateTime Last login date
	 */
	public $lastLoginDate;

	/**
	 * @var integer Invalid login count
	 */
	public $invalidLoginCount;

	/**
	 * @var \DateTime Last invalid login date
	 */
	public $lastInvalidLoginDate;

	/**
	 * @var \DateTime Lockout date
	 */
	public $lockoutDate;

	/**
	 * @var boolean Password reset required
	 */
	public $passwordResetRequired = false;

	/**
	 * @var \DateTime Last password change date
	 */
	public $lastPasswordChangeDate;

	/**
	 * @var string Unverified email
	 */
	public $unverifiedEmail;

	/**
	 * @var string New password
	 */
	public $newPassword;

	/**
	 * @var string Current password
	 */
	public $currentPassword;

	/**
	 * @var \DateTime Verification code issued date
	 */
	public $verificationCodeIssuedDate;

	/**
	 * @var string Auth error
	 */
	public $authError;

	/**
	 * The cached list of groups the user belongs to. Set by [[getGroups()]].
	 *
	 * @var array
	 */
	private $_groups;

	// Public Methods
	// =========================================================================

	/**
	 * Use the full name or username as the string representation.
	 *
	 * @return string
	 */
	public function __toString()
	{
		try
		{
			if (Craft::$app->config->get('useEmailAsUsername'))
			{
				return $this->email;
			}
			else
			{
				return $this->username;
			}
		}
		catch (Exception $e)
		{
			ErrorHandler::convertExceptionToError($e);
		}
	}

	/**
	 * @inheritdoc
	 */
	public function rules()
	{
		$rules = parent::rules();

		$rules[] = [['preferredLocale'], 'craft\\app\\validators\\Locale'];
		$rules[] = [['weekStartDay'], 'number', 'min' => -2147483648, 'max' => 2147483647, 'integerOnly' => true];
		$rules[] = [['lastLoginDate'], 'craft\\app\\validators\\DateTime'];
		$rules[] = [['invalidLoginCount'], 'number', 'min' => -2147483648, 'max' => 2147483647, 'integerOnly' => true];
		$rules[] = [['lastInvalidLoginDate'], 'craft\\app\\validators\\DateTime'];
		$rules[] = [['lockoutDate'], 'craft\\app\\validators\\DateTime'];
		$rules[] = [['lastPasswordChangeDate'], 'craft\\app\\validators\\DateTime'];
		$rules[] = [['verificationCodeIssuedDate'], 'craft\\app\\validators\\DateTime'];
		$rules[] = [['email', 'unverifiedEmail'], 'email'];
		$rules[] = [['email', 'unverifiedEmail'], 'string', 'min' => 5];
		$rules[] = [['username'], 'string', 'max' => 100];
		$rules[] = [['email', 'unverifiedEmail'], 'string', 'max' => 255];

		return $rules;
	}

	/**
	 * @inheritdoc
	 */
	public static function findIdentity($id)
	{
		$user = Craft::$app->users->getUserById($id);

		if ($user->status == UserStatus::Active)
		{
			return $user;
		}
	}

	/**
	 * @inheritdoc
	 */
	public static function findIdentityByAccessToken($token, $type = null)
	{
		throw new NotSupportedException('"findIdentityByAccessToken" is not implemented.');
	}

	/**
	 * Returns the authentication data from a given auth key.
	 *
	 * @param string $authKey
	 *
	 * @return array|null The authentication data, or `null` if it was invalid.
	 */
	public static function getAuthData($authKey)
	{
		$data = json_decode($authKey, true);

		if (count($data) === 3 && isset($data[0], $data[1], $data[2]))
		{
			return $data;
		}
	}

	/**
	 * @inheritdoc
	 */
	public function getAuthKey()
	{
		$token = Craft::$app->getSecurity()->generateRandomString(100);
		$tokenUid = $this->_storeSessionToken($token);
		$userAgent = Craft::$app->getRequest()->getUserAgent();

		// The auth key is a combination of the hashed token, its row's UID, and the user agent string
		return json_encode([
			$token,
			$tokenUid,
			$userAgent,
		], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * @inheritdoc
	 */
	public function validateAuthKey($authKey)
	{
		$data = static::getAuthData($authKey);

		if ($data)
		{
			list($token, $tokenUid, $userAgent) = $data;

			return (
				$this->_validateUserAgent($userAgent) &&
				($token === $this->_findSessionTokenByUid($tokenUid))
			);
		}

		return false;
	}

	/**
	 * Determines whether the user is allowed to be logged in with a given password.
	 *
	 * @param string $password The user's plain text passwerd.
	 *
	 * @return bool
	 */
	public function authenticate($password)
	{
		switch ($this->status)
		{
			case UserStatus::Archived:
			{
				$this->authError = AuthError::InvalidCredentials;
				return false;
			}

			case UserStatus::Pending:
			{
				$this->authError = AuthError::PendingVerification;
				return false;
			}

			case UserStatus::Suspended:
			{
				$this->errorCode = AuthError::AccountSuspended;
				return false;
			}

			case UserStatus::Locked:
			{
				if (Craft::$app->config->get('cooldownDuration'))
				{
					$this->authError = AuthError::AccountCooldown;
				}
				else
				{
					$this->authError = AuthError::AccountLocked;
				}
				return false;
			}

			case UserStatus::Active:
			{
				// Validate the password
				if (!Craft::$app->getSecurity()->validatePassword($password, $this->password))
				{
					Craft::$app->users->handleInvalidLogin($this);

					// Was that one bad password too many?
					if ($this->status == UserStatus::Locked)
					{
						// Will set the authError to either AccountCooldown or AccountLocked
						return $this->authenticate($password);
					}
					else
					{
						$this->authError = AuthError::InvalidCredentials;
						return false;
					}
				}

				// Is a password reset required?
				if ($this->passwordResetRequired)
				{
					$this->authError = AuthError::PasswordResetRequired;
					return false;
				}

				$request = Craft::$app->getRequest();

				if (!$request->getIsConsoleRequest() && $request->getIsCpRequest())
				{
					if (!$this->can('accessCp'))
					{
						$this->authError = AuthError::NoCpAccess;
						return false;
					}

					if (!Craft::$app->isSystemOn() && !$this->can('accessCpWhenSystemIsOff'))
					{
						$this->authError = AuthError::NoCpOfflineAccess;
						return false;
					}
				}

				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the reference string to this element.
	 *
	 * @return string|null
	 */
	public function getRef()
	{
		return $this->username;
	}

	/**
	 * Returns the user's groups.
	 *
	 * @param string|null $indexBy
	 *
	 * @return array
	 */
	public function getGroups($indexBy = null)
	{
		if (!isset($this->_groups))
		{
			if (Craft::$app->getEdition() == Craft::Pro)
			{
				$this->_groups = Craft::$app->userGroups->getGroupsByUserId($this->id);
			}
			else
			{
				$this->_groups = [];
			}
		}

		if (!$indexBy)
		{
			$groups = $this->_groups;
		}
		else
		{
			$groups = [];

			foreach ($this->_groups as $group)
			{
				$groups[$group->$indexBy] = $group;
			}
		}

		return $groups;
	}

	/**
	 * Returns whether the user is in a specific group.
	 *
	 * @param mixed $group The user group model, its handle, or ID.
	 *
	 * @return bool
	 */
	public function isInGroup($group)
	{
		if (Craft::$app->getEdition() == Craft::Pro)
		{
			if (is_object($group) && $group instanceof UserGroup)
			{
				$group = $group->id;
			}

			if (is_numeric($group))
			{
				$groups = array_keys($this->getGroups('id'));
			}
			else if (is_string($group))
			{
				$groups = array_keys($this->getGroups('handle'));
			}

			if (!empty($groups))
			{
				return in_array($group, $groups);
			}
		}

		return false;
	}

	/**
	 * Gets the user's full name.
	 *
	 * @return string|null
	 */
	public function getFullName()
	{
		$firstName = trim($this->firstName);
		$lastName = trim($this->lastName);

		return $firstName.($firstName && $lastName ? ' ' : '').$lastName;
	}

	/**
	 * Returns the user's full name or username.
	 *
	 * @return string
	 */
	public function getName()
	{
		$fullName = $this->getFullName();

		if ($fullName)
		{
			return $fullName;
		}
		else
		{
			return $this->username;
		}
	}

	/**
	 * Gets the user's first name or username.
	 *
	 * @return string|null
	 */
	public function getFriendlyName()
	{
		if ($firstName = trim($this->firstName))
		{
			return $firstName;
		}
		else
		{
			return $this->username;
		}
	}

	/**
	 * @inheritdoc
	 */
	public function getStatus()
	{
		if ($this->locked)
		{
			return UserStatus::Locked;
		}

		if ($this->suspended)
		{
			return UserStatus::Suspended;
		}

		if ($this->archived)
		{
			return UserStatus::Archived;
		}

		if ($this->pending)
		{
			return UserStatus::Pending;
		}

		return UserStatus::Active;
	}

	/**
	 * Sets a user's status to active.
	 *
	 * @return null
	 */
	public function setActive()
	{
		$this->pending = false;
		$this->locked = false;
		$this->suspended = false;
		$this->archived = false;
	}

	/**
	 * Returns the URL to the user's photo.
	 *
	 * @param int $size
	 *
	 * @return string|null
	 */
	public function getPhotoUrl($size = 100)
	{
		if ($this->photo)
		{
			return UrlHelper::getResourceUrl('userphotos/'.$this->username.'/'.$size.'/'.$this->photo);
		}
	}

	/**
	 * @inheritdoc
	 */
	public function getThumbUrl($size = 100)
	{
		$url = $this->getPhotoUrl($size);
		if (!$url)
		{
			$url = UrlHelper::getResourceUrl('defaultuserphoto/'.$size);
		}

		return $url;
	}

	/**
	 * @inheritdoc
	 */
	public function isEditable()
	{
		return Craft::$app->getUser()->checkPermission('editUsers');
	}

	/**
	 * Returns whether this is the current logged-in user.
	 *
	 * @return bool
	 */
	public function isCurrent()
	{
		if ($this->id)
		{
			$currentUser = Craft::$app->getUser()->getIdentity();

			if ($currentUser)
			{
				return ($this->id == $currentUser->id);
			}
		}

		return false;
	}

	/**
	 * Returns whether the user has permission to perform a given action.
	 *
	 * @param string $permission
	 *
	 * @return bool
	 */
	public function can($permission)
	{
		if (Craft::$app->getEdition() == Craft::Pro)
		{
			if ($this->admin || $this->client)
			{
				return true;
			}
			else if ($this->id)
			{
				return Craft::$app->userPermissions->doesUserHavePermission($this->id, $permission);
			}
			else
			{
				return false;
			}
		}
		else
		{
			return true;
		}
	}

	/**
	 * Returns whether the user has shunned a given message.
	 *
	 * @param string $message
	 *
	 * @return bool
	 */
	public function hasShunned($message)
	{
		if ($this->id)
		{
			return Craft::$app->users->hasUserShunnedMessage($this->id, $message);
		}
		else
		{
			return false;
		}
	}

	/**
	 * Returns the time when the user will be over their cooldown period.
	 *
	 * @return DateTime|null
	 */
	public function getCooldownEndTime()
	{
		if ($this->status == UserStatus::Locked)
		{
			$cooldownEnd = clone $this->lockoutDate;
			$cooldownEnd->add(new DateInterval(Craft::$app->config->get('cooldownDuration')));

			return $cooldownEnd;
		}
	}

	/**
	 * Returns the remaining cooldown time for this user, if they've entered their password incorrectly too many times.
	 *
	 * @return DateInterval|null
	 */
	public function getRemainingCooldownTime()
	{
		if ($this->status == UserStatus::Locked)
		{
			$currentTime = DateTimeHelper::currentUTCDateTime();
			$cooldownEnd = $this->getCooldownEndTime();

			if ($currentTime < $cooldownEnd)
			{
				return $currentTime->diff($cooldownEnd);
			}
		}
	}

	/**
	 * @inheritdoc
	 */
	public function getCpEditUrl()
	{
		if ($this->isCurrent())
		{
			return UrlHelper::getCpUrl('myaccount');
		}
		else if (Craft::$app->getEdition() == Craft::Client && $this->client)
		{
			return UrlHelper::getCpUrl('clientaccount');
		}
		else if (Craft::$app->getEdition() == Craft::Pro)
		{
			return UrlHelper::getCpUrl('users/'.$this->id);
		}
		else
		{
			return false;
		}
	}

	/**
	 * @inheritdoc
	 */
	public static function populateModel($attributes)
	{
		/** @var User $user */
		$user = parent::populateModel($attributes);

		// Is the user in cooldown mode, and are they past their window?
		if ($user->status == UserStatus::Locked)
		{
			$cooldownDuration = Craft::$app->config->get('cooldownDuration');

			if ($cooldownDuration)
			{
				if (!$user->getRemainingCooldownTime())
				{
					Craft::$app->users->activateUser($user);
				}
			}
		}

		return $user;
	}

	/**
	 * Validates all of the attributes for the current Model. Any attributes that fail validation will additionally get
	 * logged to the `craft/storage/logs` folder as a warning.
	 *
	 * In addition, we check that the username does not have any whitespace in it.
	 *
	 * @param null $attributes
	 * @param bool $clearErrors
	 *
	 * @return bool|null
	 */
	public function validate($attributes = null, $clearErrors = true)
	{
		// Don't allow whitespace in the username.
		if (preg_match('/\s+/', $this->username))
		{
			$this->addError('username', Craft::t('app', 'Spaces are not allowed in the username.'));
		}

		return parent::validate($attributes, false);
	}

	// Private Methods
	// =========================================================================

	/**
	 * Saves a new session record for the user.
	 *
	 * @param string $sessionToken
	 *
	 * @return string The new session row's UID.
	 */
	private function _storeSessionToken($sessionToken)
	{
		$sessionRecord = new SessionRecord();
		$sessionRecord->userId = $this->id;
		$sessionRecord->token = $sessionToken;
		$sessionRecord->save();
		return $sessionRecord->uid;
	}

	/**
	 * Finds a session token by its row's UID.
	 *
	 * @param string $uid
	 *
	 * @return string|null The session token, or `null` if it could not be found.
	 */
	private function _findSessionTokenByUid($uid)
	{
		return (new Query())
			->select('token')
			->from('{{%sessions}}')
			->where(['and', 'userId=:userId', 'uid=:uid'], [':userId' => $this->id, ':uid' => $uid])
			->scalar();
	}

	/**
	 * Validates a cookie's stored user agent against the current request's user agent string,
	 * if the 'requireMatchingUserAgentForSession' config setting is enabled.
	 *
	 * @param string $userAgent
	 *
	 * @return boolean
	 */
	private function _validateUserAgent($userAgent)
	{
		if (Craft::$app->config->get('requireMatchingUserAgentForSession'))
		{
			$requestUserAgent = Craft::$app->getRequest()->getUserAgent();

			if ($userAgent !== $requestUserAgent)
			{
				Craft::warning('Tried to restore session from the the identity cookie, but the saved user agent ('.$userAgent.') does not match the current request’s ('.$requestUserAgent.').', __METHOD__);
				return false;
			}
		}

		return true;
	}
}