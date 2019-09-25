<?php
App::uses('AppModel', 'Model');
class UserSetting extends AppModel
{
    public $useTable = 'user_settings';

    public $recursive = -1;

    public $actsAs = array(
            'SysLogLogable.SysLogLogable' => array(
                    'userModel' => 'User',
                    'userKey' => 'user_id',
                    'change' => 'full'),
            'Containable',
    );

    public $validate = array(

    );

    public $belongsTo = array(
        'User'
    );

    public $validSettings = array(
        'publish_alert_filter' => array(
            'placeholder' => array(
                'AND' => array(
                    'NOT' => array(
                        'EventTag.name' => array(
                            '%osint%'
                        )
                    ),
                    'OR' => array(
                        'Tag.name' => array(
                            'tlp:green',
                            'tlp:amber',
                            'tlp:red',
                            '%privint%'
                        )
                    )
                )
            )
        )
    );

    // massage the data before we send it off for validation before saving anything
    public function beforeValidate($options = array())
    {
        parent::beforeValidate();
        // add a timestamp if it is not set
        if (empty($this->data['UserSetting']['timestamp'])) {
            $this->data['UserSetting']['timestamp'] = time();
        }
        if (!empty($this->data['UserSetting']['value'])) {
            if (is_array($this->data['UserSetting']['value'])) {
                $this->data['UserSetting']['value'] = json_encode($this->data['UserSetting']['value']);
            }
        } else {
            $this->data['UserSetting']['value'] = '[]';
        }
        return true;
    }

    // Once we run a find, let us decode the JSON field so we can interact with the contents as if it was an array
    public function afterFind($results, $primary = false)
    {
        foreach ($results as $k => $v) {
            $results[$k]['UserSetting']['value'] = json_decode($v['UserSetting']['value'], true);
        }
        return $results;
    }

    public function checkSettingValidity($setting)
    {
        return isset($this->validSettings[$setting]);
    }

    /*
     * canModify expects an auth user object or a user ID and a loaded setting as input parameters
     * check if the user can modify/remove the given entry
     * returns true for site admins
     * returns true for org admins if setting["User"]["org_id"] === $user["org_id"]
     * returns true for any user if setting["user_id"] === $user["id"]
     */
    public function checkAccess($user, $setting)
    {
        if (is_numeric($user)) {
            $user = $this->User->getAuthUser($user);
        }
        if ($user['Role']['perm_site_admin']) {
            return true;
        } else if ($user['Role']['perm_admin']) {
            if ($user['org_id'] === $setting['User']['org_id']) {
                return true;
            }
        } else {
            if ($user['id'] === $setting['UserSetting']['user_id']) {
                return true;
            }
        }
        return false;
    }

    /*
     *  Check whether the event is something the user is interested (to be alerted on)
     *
     */
    public function checkPublishFilter($user, $event)
    {
        $rule = $this->find('first', array(
            'recursive' => -1,
            'conditions' => array(
                'UserSetting.user_id' => $user['id'],
                'UserSetting.setting' => 'publish_alert_filter'
            )
        ));
        if (empty($rule)) {
            return true;
        }
        return $this->__recursiveConvert($rule['UserSetting']['value'], $event);
    }

    /*
     * Convert a complex rule set recursively
     * takes as params a rule branch and an event to check against
     * evaluate whether the rule set evaluates as true/false
     * The full evaluation involves resolving the boolean branches
     * valid boolean operators are OR, AND, NOT all capitalised as strings
     */
    private function __recursiveConvert($rule, $event)
    {
        $toReturn = array();
        if (is_array($rule)) {
            foreach ($rule as $k => $v) {
                if (in_array($k, array('OR', 'NOT', 'AND'))) {
                    $parts = $this->__recursiveConvert($v, $event);
                    $temp = null;
                    foreach ($parts as $partValue) {
                        if ($temp === null) {
                            $temp = $k === 'NOT' ? !$partValue : $partValue;
                        } else {
                            if ($k === 'OR') {
                                $temp = $temp || $partValue;
                            } elseif ($k === 'AND') {
                                $temp = $temp && $partValue;
                            } else {
                                $temp = $temp && !$partValue;
                            }
                        }
                    }
                    $toReturn []= $temp;
                } else {
                    $v = mb_strtolower($v);
                    $toReturn []= $this->__checkEvent($k, $v, $event);
                }
            }
            return $toReturn;
        } else {
            return false;
        }
    }

    /*
     * Checks if an event matches the given rule
     * valid filters:
     * - AttributeTag.name
     * - EventTag.name
     * - Tag.name (checks against both event and attribute tags)
     * - Orgc.uuid
     * - Orgc.name
     * Values passed can be used for direct string comparisons or alternatively
     * as substring matches by encapsulating the string in a pair of "%" characters
     * Each rule can take a list of values
     */
    private function __checkEvent($rule, $lookup_values, $event)
    {
        if (!is_array($lookup_values)) {
            $lookup_values = array($lookup_values);
        }
        if ($rule === 'AttributeTag.name') {
            $values = array_merge(
                Hash::extract($event, 'Attribute.{n}.AttributeTag.{n}.Tag.name'),
                Hash::extract($event, 'Object.{n}.Attribute.{n}.AttributeTag.{n}.Tag.name')
            );
        } else if ($rule === 'EventTag.name') {
            $values = Hash::extract($event, 'EventTag.{n}.Tag.name');
        } else if ($rule === 'Orgc.name') {
            $values = array($event['Event']['Orgc']['name']);
        } else if ($rule === 'Orgc.uuid') {
            $values = array($event['Event']['Orgc']['uuid']);
        } else if ($rule === 'Tag.name') {
            $values = array_merge(
                Hash::extract($event, 'Attribute.{n}.AttributeTag.{n}.Tag.name'),
                Hash::extract($event, 'Object.{n}.Attribute.{n}.AttributeTag.{n}.Tag.name'),
                Hash::extract($event, 'EventTag.{n}.Tag.name')
            );
        }
        if (!empty($values)) {
            foreach ($values as $extracted_value) {
                $extracted_value = mb_strtolower($extracted_value);
                foreach ($lookup_values as $lookup_value) {
                    $lookup_value_trimmed = trim($lookup_value, "%");
                    if (strlen($lookup_value_trimmed) != strlen($lookup_value)) {
                        if (strpos($extracted_value, $lookup_value_trimmed) !== false) {
                            return true;
                        }
                    } else {
                        if ($extracted_value === $lookup_value) {
                            return true;
                        }
                    }
                }
            }
        }
        return false;
    }
}

