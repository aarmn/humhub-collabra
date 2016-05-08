<?php

/**
 * @link https://www.humhub.org/
 * @copyright Copyright (c) 2015 HumHub GmbH & Co. KG
 * @license https://www.humhub.com/licences
 */

namespace humhub\modules\user\models;

use Yii;

/**
 * This is the model class for table "user_invite".
 *
 * @property integer $id
 * @property integer $user_originator_id
 * @property integer $space_invite_id
 * @property string $email
 * @property string $source
 * @property string $token
 * @property string $created_at
 * @property integer $created_by
 * @property string $updated_at
 * @property integer $updated_by
 * @property string $language
 * @property string $firstname
 * @property string $lastname
 */
class Invite extends \yii\db\ActiveRecord
{

    const SOURCE_SELF = "self";
    const SOURCE_INVITE = "invite";
    const TOKEN_LENGTH = 12;

    /**
     * @inheritdoc
     */
    public static function tableName()
    {
        return 'user_invite';
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['user_originator_id', 'space_invite_id', 'created_by', 'updated_by'], 'integer'],
            [['token'], 'unique'],
            [['firstname', 'lastname'], 'string', 'max' => 255],
            [['email', 'source', 'token'], 'string', 'max' => 45],
            [['language'], 'string', 'max' => 10],
            [['email'], 'required'],
            [['email'], 'unique'],
            [['email'], 'email'],
            [['email'], 'unique', 'targetClass' => \humhub\modules\user\models\User::className(), 'message' => Yii::t('UserModule.base', 'E-Mail is already in use! - Try forgot password.')],
        ];
    }

    /**
     * @inheritdoc
     */
    public function scenarios()
    {
        $scenarios = parent::scenarios();
        $scenarios['invite'] = ['email'];
        return $scenarios;
    }

    /**
     * @inheritdoc
     */
    public function beforeSave($insert)
    {
        if ($insert && $this->token == '') {
            $this->token = Yii::$app->security->generateRandomString(self::TOKEN_LENGTH);
        }

        return parent::beforeSave($insert);
    }

    public function selfInvite()
    {
        $this->source = self::SOURCE_SELF;
        $this->language = Yii::$app->language;

        // Delete existing invite for e-mail - but reuse token
        $existingInvite = Invite::findOne(['email' => $this->email]);
        if ($existingInvite !== null) {
            $this->token = $existingInvite->token;
            $existingInvite->delete();
        }

        if ($this->allowSelfInvite() && $this->validate() && $this->save()) {
            $this->sendInviteMail();
            return true;
        }

        return false;
    }

    /**
     * Sends the invite e-mail
     */
    public function sendInviteMail()
    {

        // User requested registration link by its self
        if ($this->source == self::SOURCE_SELF) {

            $mail = Yii::$app->mailer->compose([
                'html' => '@humhub/modules/user/views/mails/UserInviteSelf',
                'text' => '@humhub/modules/user/views/mails/plaintext/UserInviteSelf'
                    ], ['token' => $this->token]);
            $mail->setFrom([\humhub\models\Setting::Get('mailer.systemEmailAddress') => \humhub\models\Setting::Get('mailer.systemEmailName')]);
            $mail->setTo($this->email);
            $mail->setSubject(Yii::t('UserModule.views_mails_UserInviteSelf', 'Registration Link'));
            $mail->send();
        } elseif ($this->source == self::SOURCE_INVITE) {

            // Switch to systems default language
            Yii::$app->language = \humhub\models\Setting::Get('defaultLanguage');

            $mail = Yii::$app->mailer->compose([
                'html' => '@humhub/modules/user/views/mails/UserInviteSpace',
                'text' => '@humhub/modules/user/views/mails/plaintext/UserInviteSpace'
                    ], [
                'token' => $this->token,
                'originator' => $this->originator,
                'originatorName' => $this->originator->displayName,
                'token' => $this->token,
                'space' => $this->space
            ]);
            $mail->setFrom([\humhub\models\Setting::Get('mailer.systemEmailAddress') => \humhub\models\Setting::Get('mailer.systemEmailName')]);
            $mail->setTo($this->email);
            $mail->setSubject(Yii::t('UserModule.views_mails_UserInviteSpace', 'Space Invite'));
            $mail->send();

            // Switch back to users language
            if (Yii::$app->user->language !== "") {
                Yii::$app->language = Yii::$app->user->language;
            }
        }
    }

    /**
     * Return user which triggered this invite
     *
     * @return \yii\db\ActiveQuery
     */
    public function getOriginator()
    {
        return $this->hasOne(\humhub\modules\user\models\User::className(), ['id' => 'user_originator_id']);
    }

    /**
     * Return space which is involved in this invite
     *
     * @return \yii\db\ActiveQuery
     */
    public function getSpace()
    {
        return $this->hasOne(\humhub\modules\space\models\Space::className(), ['id' => 'space_invite_id']);
    }

    /**
     * Allow users to invite themself
     *
     * @return boolean allow self invite
     */
    public function allowSelfInvite()
    {
        return (\humhub\models\Setting::Get('auth.anonymousRegistration', 'user'));
    }

}
