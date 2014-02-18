<?php

/*
 * This file is part of the Dektrium project.
 *
 * (c) Dektrium project <http://github.com/dektrium/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace dektrium\user\models;

use yii\db\ActiveRecord;
use yii\helpers\Security;

/**
 * User ActiveRecord model.
 *
 * @property integer $id
 * @property string  $username
 * @property string  $email
 * @property string  $password_hash
 * @property string  $auth_key
 * @property integer $registered_from
 * @property integer $logged_in_from
 * @property integer $logged_in_at
 * @property string  $confirmation_token
 * @property integer $confirmation_sent_at
 * @property integer $confirmed_at
 * @property string  $unconfirmed_email
 * @property string  $recovery_token
 * @property integer $recovery_sent_at
 * @property integer $blocked_at
 * @property string  $role
 * @property integer $created_at
 * @property integer $updated_at
 *
 * @author Dmitry Erofeev <dmeroff@gmail.com>
 */
class User extends ActiveRecord implements UserInterface
{
	/**
	 * @var string Plain password. Used for model validation.
	 */
	public $password;

	/**
	 * @var string Verification code.
	 */
	public $verifyCode;

	/**
	 * @var \dektrium\user\Module
	 */
	private $_module;

	/**
	 * @inheritdoc
	 */
	public static function createQuery()
	{
		return new UserQuery(['modelClass' => get_called_class()]);
	}

	public function attributeLabels()
	{
		return [
			'username' => \Yii::t('user', 'Username'),
			'email' => \Yii::t('user', 'Email'),
			'password' => \Yii::t('user', 'Password'),
			'created_at' => \Yii::t('user', 'Registration time'),
			'registered_from' => \Yii::t('user', 'Registered from'),
		];
	}

	/**
	 * @inheritdoc
	 */
	public function scenarios()
	{
		$attributes = $this->getModule()->generatePassword ? ['username', 'email'] : ['username', 'email', 'password'];
		if (in_array('register', $this->getModule()->captcha)) {
			$attributes[] = 'verifyCode';
		}
		return [
			'register' => $attributes,
			'create'   => ['username', 'email', 'password'],
			'update'   => ['username', 'email', 'password'],
			'reset'    => ['password'],
		];
	}

	/**
	 * @inheritdoc
	 */
	public function rules()
	{
		$rules = [
			[['username', 'email', 'password'], 'required', 'on' => ['create']],
			[['username', 'email'], 'required', 'on' => ['update']],
			['email', 'email'],
			[['username', 'email'], 'unique'],
			['username', 'match', 'pattern' => '/^[a-zA-Z]\w+$/'],
			['username', 'string', 'min' => 3, 'max' => 25],
			['email', 'string', 'max' => 255],
		];

		if ($this->getModule()->generatePassword) {
			$rules[] = [['username', 'email'], 'required', 'on' => ['register']];
		} else {
			$rules[] = [['username', 'email', 'password'], 'required', 'on' => ['register']];
			$rules[] = ['password', 'string', 'min' => 6, 'on' => ['register']];
		}

		if (in_array('register', $this->getModule()->captcha)) {
			$rules[] = ['verifyCode', 'captcha', 'captchaAction' => 'user/default/captcha', 'on' => ['register']];
		}

		return $rules;
	}

	/**
	 * @inheritdoc
	 */
	public function beforeSave($insert)
	{
		if (parent::beforeSave($insert)) {
			if ($this->isAttributeSafe('password') && !empty($this->password)) {
				$this->setAttribute('password_hash', Security::generatePasswordHash($this->password, $this->getModule()->cost));
			}
			if ($this->isNewRecord) {
				$this->setAttribute('auth_key', Security::generateRandomKey());
				$this->setAttribute('created_at', time());
			}
			$this->setAttribute('updated_at', time());

			return true;
		} else {
			return false;
		}
	}

	/**
	 * @inheritdoc
	 */
	public function afterSave($insert)
	{
		if ($insert) {
			$profile = new Profile();
			$profile->user_id = $this->id;
			$profile->save(false);
		}
		parent::afterSave($insert);
	}

	/**
	 * @inheritdoc
	 */
	public static function tableName()
	{
		return '{{%user}}';
	}

	/**
	 * @inheritdoc
	 */
	public static function findIdentity($id)
	{
		return static::find($id);
	}

	/**
	 * @inheritdoc
	 */
	public function getId()
	{
		return $this->getAttribute('id');
	}

	/**
	 * @inheritdoc
	 */
	public function getAuthKey()
	{
		return $this->getAttribute('auth_key');
	}

	/**
	 * @inheritdoc
	 */
	public function validateAuthKey($authKey)
	{
		return $this->getAttribute('auth_key') == $authKey;
	}

	/**
	 * Registers a user.
	 *
	 * @return bool
	 * @throws \RuntimeException
	 */
	public function register()
	{
		if (!$this->getIsNewRecord()) {
			throw new \RuntimeException('Calling "'.__CLASS__.'::register()" on existing user');
		}

		if ($this->validate()) {
			$module = $this->getModule();
			if ($module->generatePassword) {
				$this->password = $this->generatePassword(8);
				$this->sendMessage(\Yii::t('user', 'Welcome to {sitename}', ['sitename' => \Yii::$app->name]),
					'welcome', ['user' => $this, 'password' => $this->password]);
			}

			if ($module->trackable) {
				$this->setAttribute('registered_from', ip2long(\Yii::$app->getRequest()->getUserIP()));
			}

			if ($module->confirmable) {
				$this->generateConfirmationData();
				$isSaved = $this->save(false);
				$this->sendMessage(\Yii::t('user', 'Please confirm your account'), 'confirmation', ['user' => $this]);
				return $isSaved;
			} else {
				return $this->save(false);
			}
		}

		return false;
	}

	/**
	 * Generates user-friendly random password containing at least one lower case letter, one uppercase letter and one
	 * digit. The remaining characters in the password are chosen at random from those four sets.
	 * @see https://gist.github.com/tylerhall/521810
	 * @param $length
	 * @return string
	 */
	protected function generatePassword($length)
	{
		$sets = [
			'abcdefghjkmnpqrstuvwxyz',
			'ABCDEFGHJKMNPQRSTUVWXYZ',
			'23456789'
		];
		$all = '';
		$password = '';
		foreach($sets as $set) {
			$password .= $set[array_rand(str_split($set))];
			$all .= $set;
		}

		$all = str_split($all);
		for ($i = 0; $i < $length - count($sets); $i++) {
			$password .= $all[array_rand($all)];
		}

		$password = str_shuffle($password);

		return $password;
	}

	/**
	 * Confirms a user by setting it's "confirmation_time" to actual time
	 *
	 * @return bool
	 */
	public function confirm()
	{
		if ($this->getIsConfirmed()) {
			return true;
		} elseif ($this->getIsConfirmationPeriodExpired()) {
			return false;
		}

		$this->confirmation_token = null;
		$this->confirmation_sent_at = null;
		$this->confirmed_at = time();

		return $this->save(false);
	}

	/**
	 * Generates confirmation data and re-sends confirmation instructions by email.
	 *
	 * @return bool
	 */
	public function resend()
	{
		$this->generateConfirmationData();
		$this->save(false);

		return $this->sendMessage(\Yii::t('user', 'Please confirm your account'), 'confirmation', ['user' => $this]);
	}

	/**
	 * Generates confirmation data.
	 */
	protected function generateConfirmationData()
	{
		$this->confirmation_token = Security::generateRandomKey();
		$this->confirmation_sent_at = time();
		$this->confirmed_at = null;
	}

	/**
	 * @return string Confirmation url.
	 */
	public function getConfirmationUrl()
	{
		return $this->getIsConfirmed() ? null :
			\Yii::$app->getUrlManager()->createAbsoluteUrl('/user/registration/confirm', [
				'id'    => $this->id,
				'token' => $this->confirmation_token
			]);
	}

	/**
	 * Verifies whether a user is confirmed or not.
	 *
	 * @return bool
	 */
	public function getIsConfirmed()
	{
		return $this->confirmed_at !== null;
	}

	/**
	 * Checks if the user confirmation happens before the token becomes invalid.
	 *
	 * @return bool
	 */
	public function getIsConfirmationPeriodExpired()
	{
		return $this->confirmation_sent_at != null && ($this->confirmation_sent_at + $this->getModule()->confirmWithin) < time();
	}

	/**
	 * Resets password and sets recovery token to null
	 *
	 * @param  string $password
	 * @return bool
	 */
	public function reset($password)
	{
		$this->scenario = 'reset';
		$this->password = $password;
		$this->recovery_token = null;
		$this->recovery_sent_at = null;

		return $this->save(false);
	}

	/**
	 * Checks if the password recovery happens before the token becomes invalid.
	 *
	 * @return bool
	 */
	public function getIsRecoveryPeriodExpired()
	{
		return ($this->recovery_sent_at + $this->getModule()->recoverWithin) < time();
	}

	/**
	 * @return string Recovery url
	 */
	public function getRecoveryUrl()
	{
		return \Yii::$app->getUrlManager()->createAbsoluteUrl('/user/recovery/reset', [
			'id' => $this->id,
			'token' => $this->recovery_token
		]);
	}

	/**
	 * Generates recovery data and sends recovery message to user.
	 */
	public function sendRecoveryMessage()
	{
		$this->recovery_token = Security::generateRandomKey();
		$this->recovery_sent_at = time();
		$this->save(false);

		return $this->sendMessage(\Yii::t('user', 'Please complete password reset'), 'recovery', ['user' => $this]);
	}

	/**
	 * Blocks the user by setting 'blocked_at' field to current time.
	 */
	public function block()
	{
		$this->blocked_at = time();

		return $this->save(false);
	}

	/**
	 * Blocks the user by setting 'blocked_at' field to null.
	 */
	public function unblock()
	{
		$this->blocked_at = null;

		return $this->save(false);
	}

	/**
	 * @return bool Whether user is blocked.
	 */
	public function getIsBlocked()
	{
		return !is_null($this->blocked_at);
	}

	/**
	 * @return null|\dektrium\user\Module
	 */
	protected function getModule()
	{
		if ($this->_module == null) {
			$this->_module = \Yii::$app->getModule('user');
		}

		return $this->_module;
	}

	/**
	 * Sends message.
	 *
	 * @param  string $subject
	 * @param  string $view
	 * @param  array  $params
	 *
	 * @return bool
	 */
	protected function sendMessage($subject, $view, $params)
	{
		$mail = \Yii::$app->getMail();
		$mail->viewPath = $this->getModule()->emailViewPath;

		if (empty(\Yii::$app->getMail()->messageConfig['from'])) {
			$mail->messageConfig['from'] = 'no-reply@example.com';
		}

		return $mail->compose($view, $params)
			->setTo($this->email)
			->setSubject($subject)
			->send();
	}
}