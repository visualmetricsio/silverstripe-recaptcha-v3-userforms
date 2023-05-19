<?php

namespace NSWDPC\SpamProtection;

use SilverStripe\Forms\CheckBoxField;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\UserForms\Model\EditableFormField;
use SilverStripe\Control\Controller;

/**
 * EditableRecaptchaV3Field
 * A field that adds reCAPTCHAv3 support to a user defined form
 * @author James <james.ellis@dpc.nsw.gov.au>
 */
class EditableRecaptchaV3Field extends EditableFormField
{

    /**
     * @var string
     */
    private static $singular_name = 'Captcha field';

    /**
     * @var string
     */
    private static $plural_name = 'Captcha fields';

    /**
     * @var bool
     */
    private static $has_placeholder = false;

    /**
     * Database fields
     * @var array
     */
    private static $db = [
        'Score' => 'Int',// 0-100
        'Action' => 'Varchar(255)',// custom action
        'IncludeInEmails' => 'Boolean'
    ];

    /**
     * @var array
     */
    private static $has_one = [
        'Rule' =>  RecaptchaV3Rule::class
    ];

    /**
     * Add default values to database
     * @var array
     */
    private static $defaults = [
        'Action' => 'submit',
        'IncludeInEmails' => 0
    ];

    /**
     * Summary fields
     * @var array
     */
    private static $summary_fields = [
        'Title' => 'Title',
        'FieldScore' => 'Threshold',
        'FieldAction' => 'Action'
    ];

    /**
     * Defines the database table name
     * @var string
     */
    private static $table_name = 'EditableRecaptchaV3Field';


    /**
     * Used as fallback value for default, it specified value is not valid
     * @var string
    */
    const DEFAULT_ACTION = 'submit';

    /**
     * Used as fallback value for default, it specified value is not valid
     * @var int
    */
    const DEFAULT_THRESHOLD = 50;

    /**
     * The reCAPTCHA verification value is always stored
     * Use the IncludeInEmails value to determine whether the reCAPTCHA value is included in emails
     * along with being saved to the submitted field
     * @inheritdoc
     */
    public function showInReports()
    {
        return true;
    }

    /**
     * Return the submitted field instance, with the IncludeInEmails value set as a boolean property
     * @inheritdoc
     */
    public function getSubmittedFormField()
    {
        $field = SubmittedRecaptchaV3Field::create();
        $field->setIncludeValueInEmails($this->IncludeInEmails == 1);
        return $field;
    }

    /**
     * Event handler called before writing to the database.
     */
    public function onBeforeWrite()
    {
        parent::onBeforeWrite();

        // use the default threshold score from config if the saved score is out of bounds
        if (is_null($this->Score) || $this->Score < 0 || $this->Score > 100) {
            $this->Score = $this->getDefaultThreshold();
        }

        if (!$this->Action) {
            $this->Action = $this->config()->get('defaults')['Action'];
        }

        // remove disallowed characters
        $this->Action = TokenResponse::formatAction($this->Action);

        /**
         * never require this field as it could cause weirdness with frontend validators
         */
        $this->Required = 0;

        // no placeholder
        $this->Placeholder = "";

        // always require a default title
        if (!$this->Title) {
            $this->Title = _t('NSWDPC\SpamProtection.FORM_SPAM_PROTECTION', 'Form spam protection');
        }
    }

    public function onAfterWrite()
    {
        parent::onAfterWrite();
        $this->DisplayRules()->removeAll();
    }

    /**
     * Get default threshold score as a float from configuration
     * @return int
     */
    public function getDefaultThreshold() : int
    {
        $threshold =  RecaptchaV3SpamProtector::getDefaultThreshold();
        return is_int($threshold) ? $threshold : self::DEFAULT_THRESHOLD;
    }

    /**
     * Get default threshold score as a float from configuration
     * @return string
     */
    public function getDefaultAction() : string
    {
        $action = RecaptchaV3Field::config()->get('execute_action');
        return is_string($action) && strlen($action) > 0 ? $action : self::DEFAULT_ACTION;
    }

    /**
     * Return range of allowed thresholds
     * @return array
     */
    protected function getRange()
    {
        return RecaptchaV3SpamProtector::getRange();
    }

    /**
     * CMS Fields
     * @return FieldList
     */
    public function getCMSFields()
    {
        $fields = parent::getCMSFields();

        $fields->removeByName([
            'ExtraClass', // this field can't have extra CSS stuff as it is invisible
            'Default',// there is no default value for this field
            'RightTitle',// there is no right title for this field
            'Required',// this field is always required for the form submission
            'DisplayRules'// this field is always required, therefore no display rules
        ]);

        // if there is no score yet, use the default
        if (is_null($this->Score) || $this->Score < 0 || $this->Score > 100) {
            $this->Score = $this->getDefaultThreshold();
        }
        $range_field = RecaptchaV3SpamProtector::getRangeCompositeField('Score', $this->Score);

        if (!$this->Action) {
            $this->Action = $this->config()->get('defaults')['Action'];
        }

        $fields->findOrMakeTab(
            "Root.FormSpam",
            _t(
                'NSWDPC\SpamProtection.RECAPTCHA_TAB_NAME',
                'Form spam'
            )
        );

        $fields->addFieldsToTab(
            "Root.FormSpam",
            [
                DropdownField::create(
                    'RuleID',
                    _t('NSWDPC\SpamProtection.RECAPTCHA_RULE_SELECT_TITLE', 'Select an existing captcha rule'),
                    RecaptchaV3Rule::getEnabledRules()->map('ID', 'TagDetailed')
                )->setDescription(
                    _t(
                        'NSWDPC\SpamProtection.RECAPTCHA_RULE_SELECT_DESCRIPTION',
                        'This will take precedence over the threshold and custom action, if provided below'
                    )
                )->setEmptyString(''),
                $range_field,
                RecaptchaV3SpamProtector::getActionField('Action', $this->Action),
                CheckboxField::create(
                    'IncludeInEmails',
                    _t('NSWDPC\SpamProtection.INCLUDE_IN_EMAILS', 'Include form spam verification information in emails')
                )
            ]
        );
        return $fields;
    }

    /**
     * Return the Rule, if enabled, or NULL if not
     * @return RecaptchaV3Rule|null
     */
    public function getEnabledRule()
    {
        $rule = $this->Rule;
        if ($rule && $rule->exists() && $rule->Enabled) {
            return $rule;
        }
        return null;
    }

    /**
     * Return the threshold score from either the Rule or the field here
     * @return int
     */
    public function getFieldScore() : int
    {
        $score = null;
        if ($this->exists()) {
            $score = $this->Score;
        }
        return is_int($score) ? $score : $this->getDefaultThreshold();
    }

    /**
     * Return the action from either the Rule or the field here
     * @return string
     */
    public function getFieldAction() : string
    {
        $action = null;
        if ($this->exists()) {
            $action = $this->Action;
        }
        return is_string($action) ? $action : $this->getDefaultAction();
    }

    /**
     * Return the form field with configured score and action
     * @return RecaptchaV3Field
     */
    public function getFormField()
    {

        // rule for this field. If set, overrides Score/Action set
        $rule = $this->getEnabledRule();

        $parent_form_identifier = "";
        if (($parent = $this->Parent()) && !empty($parent->URLSegment)) {
            $parent_form_identifier = $parent->URLSegment;
        }
        $field_template = EditableRecaptchaV3Field::class;
        $field_holder_template = EditableRecaptchaV3Field::class . '_holder';
        // the score used as a threshold
        $score = $this->getFieldScore();
        $score = round(($score / 100), 2);
        // the action
        $action = $this->getFieldAction();
        if (strpos($action, "/") === false) {
            $action = $parent_form_identifier . "/" . $action;
        }
        $field = RecaptchaV3Field::create($this->Name, $this->Title)
            ->setScore($score) // format for the reCAPTCHA API 0.00->1.00
            ->setExecuteAction($action, true)
            ->setFieldHolderTemplate($field_holder_template)
            ->setTemplate($field_template);
        if ($rule) {
            $field = $field->setRecaptchaV3RuleTag($rule->Tag);
        }
        $this->doUpdateFormField($field);
        return $field;
    }

    /**
     * Store the score/action/hostname (except token) as the submitted value
     * We don't need or want the token
     * @return string
     */
    public function getValueFromData($data)
    {
        // this is a new instance of the field
        $response = $this->getFormField()->getResponseFromSession();
        unset($response['token']);
        $value = json_encode($response);
        return $value;
    }
}
