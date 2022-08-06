<?php

/*
 * RCMCardDAV - CardDAV plugin for Roundcube webmail
 *
 * Copyright (C) 2011-2021 Benjamin Schieder <rcmcarddav@wegwerf.anderdonau.de>,
 *                         Michael Stilkerich <ms@mike2k.de>
 *
 * This file is part of RCMCardDAV.
 *
 * RCMCardDAV is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * (at your option) any later version.
 *
 * RCMCardDAV is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with RCMCardDAV. If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace MStilkerich\CardDavAddressbook4Roundcube\Frontend;

use MStilkerich\CardDavClient\Account;
use MStilkerich\CardDavAddressbook4Roundcube\Config;
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;

/**
 * UIFieldType:
 *   - text: a single-line text box where the user can enter text
 *   - plain: a read-only plain text shown in the form, non-interactive. Field is only shown when a value is available.
 *   - datetime: a read-only date/time plain text shown in the form, non-interactive
 *   - timestr: a text box where the user is expected to enter a time interval in the form HH[:MM[:SS]]
 *   - radio: a selection from options offered as a list of radio buttons
 *   - password: a text box where the user is expected to enter a password. Stored data will never be provided as form
 *               data.
 *
 * FieldSpec:
 *   [0]: label of the field
 *   [1]: key of the field
 *   [2]: UI type of the field
 *   [3]: (optional) default value of the field
 *   [4]: (optional) for UI type radio, a list of key-label pairs for the options of the selection
 *
 * @psalm-type UiFieldType = 'text'|'plain'|'datetime'|'timestr'|'radio'|'password'
 * @psalm-type FieldSpec = array{0: string, 1: string, 2: UiFieldType, 3?: string, 4?: list<array{string,string}>}
 * @psalm-type FieldSetSpec = array{label: string, fields: list<FieldSpec>}
 * @psalm-type FormSpec = list<FieldSetSpec>
 * @psalm-import-type FullAbookRow from AbstractDatabase
 * @psalm-import-type FullAccountRow from AbstractDatabase
 * @psalm-import-type AbookSettings from AddressbookManager
 * @psalm-import-type AccountSettings from AddressbookManager
 */
class UI
{
    /** @var FormSpec UI_FORM_NEWACCOUNT */
    private const UI_FORM_NEWACCOUNT = [
        [
            'label' => 'newaccount',
            'fields' => [
                [ 'accountname', 'name', 'text' ],
                [ 'discoveryurl', 'url', 'text' ],
                [ 'cd_username', 'username', 'text' ],
                [ 'cd_password', 'password', 'password' ],
            ]
        ],
        [
            'label' => 'miscsettings',
            'fields' => [
                [ 'rediscover_time', 'rediscover_time', 'timestr', '86400' ],
                [ 'cd_refresh_time', 'refresh_time', 'timestr', '3600' ],
                [
                    'newgroupstype',
                    'use_categories',
                    'radio',
                    '1',
                    [
                        [ '0', 'grouptype_vcard' ],
                        [ '1', 'grouptype_categories' ],
                    ]
                ],
            ]
        ],
    ];

    /** @var FormSpec UI_FORM_ACCOUNT */
    private const UI_FORM_ACCOUNT = [
        [
            'label' => 'basicinfo',
            'fields' => [
                [ 'frompreset', 'presetname', 'plain' ],
                [ 'accountname', 'name', 'text' ],
                [ 'discoveryurl', 'url', 'text' ],
                [ 'cd_username', 'username', 'text' ],
                [ 'cd_password', 'password', 'password' ],
            ]
        ],
        [
            'label' => 'discoveryinfo',
            'fields' => [
                [ 'rediscover_time', 'rediscover_time', 'timestr' ],
                [ 'lastdiscovered_time', 'last_discovered', 'datetime' ],
            ]
        ],
    ];

    /** @var FormSpec UI_FORM_ABOOK */
    private const UI_FORM_ABOOK = [
        [
            'label' => 'basicinfo',
            'fields' => [
                [ 'cd_name', 'name', 'text' ],
                [ 'cd_url', 'url', 'plain' ],
            ]
        ],
        [
            'label' => 'syncinfo',
            'fields' => [
                [ 'cd_refresh_time', 'refresh_time', 'timestr' ],
                [ 'cd_lastupdate_time', 'last_updated', 'datetime' ],
            ]
        ],
        [
            'label' => 'miscsettings',
            'fields' => [
                [
                    'newgroupstype',
                    'use_categories',
                    'radio',
                    '1',
                    [
                        [ '0', 'grouptype_vcard' ],
                        [ '1', 'grouptype_categories' ],
                    ]
                ],
            ]
        ],
    ];

    /**
     * The addressbook manager.
     * @var AddressbookManager
     */
    private $abMgr;

    /**
     * Constructs a new UI object.
     *
     * @param AddressbookManager $abMgr The AddressbookManager to use.
     */
    public function __construct(AddressbookManager $abMgr)
    {
        $this->abMgr = $abMgr;

        $infra = Config::inst();
        $rc = $infra->rc();

        $rc->addHook('settings_actions', [$this, 'addSettingsAction']);

        $rc->registerAction('plugin.carddav', [$this, 'renderAddressbookList']);
        $rc->registerAction('plugin.carddav.AbToggleActive', [$this, 'actionAbToggleActive']);
        $rc->registerAction('plugin.carddav.AbDetails', [$this, 'actionAbDetails']);
        $rc->registerAction('plugin.carddav.AccDetails', [$this, 'actionAccDetails']);
        $rc->registerAction('plugin.carddav.AccRm', [$this, 'actionAccRm']);
        $rc->registerAction('plugin.carddav.AbSync', [$this, 'actionAbSync']);
        $rc->includeCSS('carddav.css');
        $rc->includeJS("carddav.js");
    }

    /**
     * Adds a carddav section in settings.
     * @psalm-param array{actions: array} $args
     */
    public function addSettingsAction(array $args): array
    {
        // register as settings action
        $args['actions'][] = [
            'action' => 'plugin.carddav',
            'class'  => 'cd_preferences', // CSS style
            'label'  => 'CardDAV_rclbl', // text display
            'title'  => 'CardDAV_rclbl', // tooltip text
            'domain' => 'carddav',
        ];

        return $args;
    }

    public function renderAddressbookList(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        $rc->setPagetitle($rc->locText('CardDAV_rclbl'));

        $rc->includeJS('treelist.js', true);
        $rc->addTemplateObjHandler('addressbookslist', [$this, 'tmplAddressbooksList']);
        $rc->sendTemplate('carddav.addressbooks');
    }

    /**
     * Template object for list of addressbooks.
     *
     * @psalm-param array{id?: string} $attrib
     * @param array $attrib Object attributes
     *
     * @return string HTML content
     */
    public function tmplAddressbooksList(array $attrib): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        if (empty($attrib['id'])) {
            $attrib['id'] = 'rcmcarddavaddressbookslist';
        }

        $abMgr = $this->abMgr;
        $accountIds = $abMgr->getAccountIds();

        $accounts = [];
        foreach ($accountIds as $accountId) {
            $accounts[$accountId] = $abMgr->getAccountConfig($accountId);
            $accounts[$accountId]['addressbooks'] = $abMgr->getAddressbookConfigsForAccount($accountId);
            // Sort accounts first by account name
            usort(
                $accounts[$accountId]['addressbooks'],
                /**
                 * @param FullAbookRow $a
                 * @param FullAbookRow $b
                 */
                function (array $a, array $b): int {
                    return strcasecmp($a['name'], $b['name']);
                }
            );
        }

        // Sort accounts first by account name
        usort(
            $accounts,
            /**
             * @param FullAccountRow $a
             * @param FullAccountRow $b
             */
            function (array $a, array $b): int {
                return strcasecmp($a['name'], $b['name']);
            }
        );

        $checkboxActive = new \html_checkbox([
                'name'    => '_active[]',
                'title'   => $rc->locText('changeactive'),
                'onclick' => \rcmail_output::JS_OBJECT_NAME .
                  ".command('plugin.carddav-AbToggleActive', {abookid: this.value, state: this.checked})",
        ]);

        $accountListItems = [];
        foreach ($accounts as $account) {
            $content = \html::a(['href' => '#'], \rcube::Q($account["name"]));

            $addressbookListItems = [];
            foreach (($account["addressbooks"] ?? []) as $abook) {
                $attribs = [
                    'id'    => 'rcmli_abook' . $abook["id"],
                    'class' => 'addressbook'
                ];

                $abookHtml = \html::a(['href' => '#'], \rcube::Q($abook["name"]));
                $abookHtml .= $checkboxActive->show($abook["active"] ? $abook['id'] : '', ['value' => $abook['id']]);
                $addressbookListItems[] = \html::tag('li', $attribs, $abookHtml);
            }

            if (!empty($addressbookListItems)) {
                $content .= \html::div('treetoggle expanded', '&nbsp;');
                $content .= \html::tag('ul', ['style' => null], implode("\n", $addressbookListItems));
            }

            $attribs = [
                'id'    => 'rcmli_acc' . $account["id"],
                'class' => 'account' . (isset($account["presetname"]) ? ' preset' : '')
            ];
            $accountListItems[] = \html::tag('li', $attribs, $content);
        }

        $rc->addGuiObject('addressbookslist', $attrib['id']);
        return \html::tag('ul', $attrib, implode('', $accountListItems));
    }

    public function actionAbToggleActive(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        $abookId = $rc->inputValue("abookid", false);
        // the state parameter is set to 0 (deactivated) or 1 (active) by the client
        $active  = $rc->inputValue("state", false);

        if (isset($abookId) && isset($active)) {
            try {
                $active = ($active == "1"); // if this is some invalid value, just consider it as deactivated
                $prefix = $active ? "" : "de";
                $this->abMgr->updateAddressbook($abookId, ['active' => $active ]);
                $rc->showMessage($rc->locText("${prefix}activateabook_success"), 'confirmation');
            } catch (\Exception $e) {
                $rc->showMessage("Activation failed!", 'error');
                $rc->clientCommand('carddav_AbResetActive', $abookId, !$active);
            }
        }
    }

    /**
     * This action is invoked to show the details of an existing account, or to create a new account.
     */
    public function actionAccDetails(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();

        $accountId = $rc->inputValue("accountid", false, \rcube_utils::INPUT_POST);
        if (isset($accountId)) {
            // POST - Settings saved
            try {
                $abMgr = $this->abMgr;

                if ($accountId == 'new') {
                    /** @psalm-var AccountSettings */
                    $newaccount = $this->getSettingsFromPOST(self::UI_FORM_ACCOUNT, []);
                    /** @psalm-var AbookSettings */
                    $abooksettings = $this->getSettingsFromPOST(self::UI_FORM_ABOOK, []);
                    $accountId = $abMgr->discoverAddressbooks($newaccount, $abooksettings);
                    $rc->clientCommand('carddav_Redirect', '');
                    $rc->sendTemplate('iframe');
                } else {
                    $account = $abMgr->getAccountConfig($accountId);
                    $fixedAttributes = $this->getFixedSettings($account['presetname']);
                    /** @psalm-var AccountSettings */
                    $newset = $this->getSettingsFromPOST(self::UI_FORM_ACCOUNT, $fixedAttributes);
                    $abMgr->updateAccount($accountId, $newset);
                }

                $rc->showMessage($rc->locText("saveok"), 'confirmation');
            } catch (\Exception $e) {
                $logger->error("Error saving account preferences: " . $e->getMessage());
                $rc->showMessage($rc->locText("savefail", ['errormsg' => $e->getMessage()]), 'error');
            }
        } else {
            // GET - Account selected in list
            $accountId = $rc->inputValue("accountid", false, \rcube_utils::INPUT_GET);
        }

        if (isset($accountId)) {
            $tmplAccountDetailsFn = function (array $attrib) use ($accountId): string {
                return $this->tmplAccountDetails($accountId, $attrib);
            };
            $rc->setPagetitle($rc->locText('accountproperties'));
            $rc->addTemplateObjHandler('accountdetails', $tmplAccountDetailsFn);
            $rc->sendTemplate('carddav.accountDetails');
        }
    }

    /**
     * This action is invoked to delete an account of the user.
     */
    public function actionAccRm(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();

        $accountId = $rc->inputValue("accountid", false, \rcube_utils::INPUT_GET);
        if (isset($accountId)) {
            try {
                $abMgr = $this->abMgr;
                $abMgr->deleteAccount($accountId);
                $rc->showMessage($rc->locText("saveok"), 'confirmation');
                $rc->clientCommand('carddav_Redirect', '');
                $rc->sendTemplate('iframe');
            } catch (\Exception $e) {
                $logger->error("Error saving account preferences: " . $e->getMessage());
                $rc->showMessage($rc->locText("savefail"), 'error');
            }
        }
    }

    /**
     * This action is invoked to resync an addressbook
     */
    public function actionAbSync(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        $abookId = $rc->inputValue("abookid", false, \rcube_utils::INPUT_GET);
        $syncType = $rc->inputValue("synctype", false, \rcube_utils::INPUT_GET);
        if (isset($abookId) && isset($syncType) && in_array($syncType, ['AbSync', 'AbClrCache'])) {
            $msgParams = [ 'name' => 'Unknown' ];
            try {
                $abMgr = $this->abMgr;
                $abook = $abMgr->getAddressbook($abookId);
                $msgParams['name'] = $abook->get_name();
                if ($syncType == 'AbSync') {
                    $msgParams['duration'] = (string) $abMgr->resyncAddressbook($abook);
                } else {
                    $abMgr->deleteAddressbooks([$abookId], false, true);
                }

                $rc->showMessage($rc->locText("${syncType}_msg_ok", $msgParams), 'notice', false);

                // reload the addressbook details page so that last_updated is updated
                $rc->clientCommand('carddav_Redirect', 'iframe');
            } catch (\Exception $e) {
                $logger = $infra->logger();
                $msgParams['errormsg'] = $e->getMessage();
                $logger->error("Failed to sync ($syncType) addressbook: " . $msgParams['errormsg']);
                $rc->showMessage($rc->locText("${syncType}_msg_fail", $msgParams), 'warning', false);
            }
        }
    }

    public function actionAbDetails(): void
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();

        $abookId = $rc->inputValue("abookid", false, \rcube_utils::INPUT_POST);
        if (isset($abookId)) {
            // POST - Settings saved
            try {
                $abMgr = $this->abMgr;
                $abookrow = $abMgr->getAddressbookConfig($abookId);
                $account = $abMgr->getAccountConfig($abookrow["account_id"]);
                $fixedAttributes = $this->getFixedSettings($account['presetname'], $abookrow['url']);
                /** @psalm-var AbookSettings */
                $newset = $this->getSettingsFromPOST(self::UI_FORM_ABOOK, $fixedAttributes);
                $abMgr->updateAddressbook($abookId, $newset);
            } catch (\Exception $e) {
                $logger->error("Error saving addressbook preferences: " . $e->getMessage());
            }
        } else {
            // GET - Addressbook selected in list
            $abookId = $rc->inputValue("abookid", false, \rcube_utils::INPUT_GET);
        }

        if (isset($abookId)) {
            $rc->setPagetitle($rc->locText('abookproperties'));
            $rc->addTemplateObjHandler('addressbookdetails', [$this, 'tmplAddressbookDetails']);
            $rc->sendTemplate('carddav.addressbookDetails');
        } else {
            $logger->warning(__METHOD__ . ": no addressbook ID found in parameters");
        }
    }

    /**
     * @param FormSpec $formSpec Specification of the form
     * @param array<string, ?string> $vals Values for the form fields
     * @param list<string> $fixedAttributes A list of non-changeable settings by choice of the admin
     */
    private function makeSettingsForm(array $formSpec, array $vals, array $fixedAttributes, array $attrib): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();

        $out = '';
        foreach ($formSpec as $fieldSet) {
            $table = new \html_table(['cols' => 2]);

            foreach ($fieldSet['fields'] as $fieldSpec) {
                [ $fieldLabel, $fieldKey, $uiType ] = $fieldSpec;

                if (isset($vals[$fieldKey])) {
                    $fieldValue = $vals[$fieldKey];
                } else {
                    // in case there was an error saving a new account, echo back what the user entered (password will
                    // be stripped by uiField())
                    $formValue = $rc->inputValue($fieldKey, false);
                    if (isset($formValue)) {
                        if ($uiType == 'timestr') {
                            $fieldValue = (string) Utils::parseTimeParameter($formValue);
                        } else {
                            $fieldValue = $formValue;
                        }
                    } else {
                        $fieldValue = $fieldSpec[3] ?? '';
                    }
                }


                // plain field is only shown when there is a value to be shown
                if ($uiType == 'plain' && $fieldValue == '') {
                    continue;
                }

                $readonly = in_array($fieldKey, $fixedAttributes);
                $table->add(['class' => 'title'], \html::label(['for' => $fieldKey], $rc->locText($fieldLabel)));
                $table->add([], $this->uiField($fieldSpec, $fieldValue, $readonly));
            }

            $out .= \html::tag(
                'fieldset',
                [],
                \html::tag('legend', [], $rc->locText($fieldSet['label'])) . $table->show($attrib)
            );
        }

        return $out;
    }

    /**
     * @return list<string> The list of fixed attributes
     */
    private function getFixedSettings(?string $presetName, ?string $abookUrl = null): array
    {
        if (!isset($presetName)) {
            return [];
        }

        $infra = Config::inst();
        $admPrefs = $infra->admPrefs();
        $preset = $admPrefs->getPreset($presetName, $abookUrl);
        return $preset['fixed'];
    }


    /**
     * @param FieldSpec $fieldSpec
     */
    private function uiField(array $fieldSpec, string $fieldValue, bool $readonly): string
    {
        [, $fieldKey, $uiType ] = $fieldSpec;

        $infra = Config::inst();
        $rc = $infra->rc();

        switch ($uiType) {
            case 'datetime':
                $t = intval($fieldValue);
                if ($t > 0) {
                    $fieldValue = date("Y-m-d H:i:s", intval($fieldValue));
                } else {
                    $fieldValue = $rc->locText('never');
                }
                return \rcube::Q($fieldValue);

            case 'plain':
                return \rcube::Q($fieldValue);

            case 'timestr':
                $t = intval($fieldValue);
                $fieldValue = sprintf("%02d:%02d:%02d", floor($t / 3600), ($t / 60) % 60, $t % 60);
                // fall through to text field

            case 'text':
                $input = new \html_inputfield([
                    'name' => $fieldKey,
                    'type' => $uiType,
                    'value' => $fieldValue,
                    'disabled' => $readonly,
                ]);
                return $input->show();

            case 'password':
                $input = new \html_inputfield([
                    'name' => $fieldKey,
                    'type' => $uiType,
                    'value' => '', // don't pass the password to the UI form
                    'disabled' => $readonly,
                ]);
                return $input->show();

            case 'radio':
                $ul = '';
                $radioBtn = new \html_radiobutton(['name' => $fieldKey]);

                foreach (($fieldSpec[4] ?? []) as $selectionSpec) {
                    [ $selValue, $selLabel ] = $selectionSpec;
                    $ul .= \html::tag(
                        'li',
                        [],
                        $radioBtn->show($fieldValue, ['value' => $selValue]) . $rc->locText($selLabel)
                    );
                }
                return \html::tag('ul', ['class' => 'proplist'], $ul);
        }

        throw new \Exception("Unknown UI element type $uiType for $fieldKey");
    }

    // INFO: name, url, group type, refresh time, time of last refresh, discovered vs. manually added,
    //       cache state (# contacts, groups, etc.), list of custom subtypes (add / delete)
    // ACTIONS: Refresh, Delete (for manually-added addressbooks), Clear local cache
    public function tmplAddressbookDetails(array $attrib): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();
        $out = '';

        try {
            // Note: abookid is provided as GET (addressbook selection) or POST parameter (settings form)
            $abookId = $rc->inputValue("abookid", false, \rcube_utils::INPUT_GP);
            if (isset($abookId)) {
                $abookrow = $this->abMgr->getAddressbookConfig($abookId);
                $account = $this->abMgr->getAccountConfig($abookrow["account_id"]);

                $fixedAttributes = $this->getFixedSettings($account['presetname'], $abookrow['url']);

                // HIDDEN FIELDS
                $abookIdField = new \html_hiddenfield(['name' => "abookid", 'value' => $abookId]);
                $out .= $abookIdField->show();

                $out .= $this->makeSettingsForm(self::UI_FORM_ABOOK, $abookrow, $fixedAttributes, $attrib);

                $out = $rc->requestForm(
                    [
                        'task' => 'settings',
                        'action' => 'plugin.carddav.AbDetails',
                        'method' => 'post',
                    ] + $attrib,
                    $out
                );
            }
        } catch (\Exception $e) {
            $logger->error($e->getMessage());
        }

        return $out;
    }

    // INFO: name, url, group type, rediscover time, time of last rediscovery
    // ACTIONS: Rediscover, Delete, Add manual addressbook
    public function tmplAccountDetails(string $accountId, array $attrib): string
    {
        $infra = Config::inst();
        $rc = $infra->rc();
        $logger = $infra->logger();
        $out = '';

        try {
            // HIDDEN FIELDS
            $accountIdField = new \html_hiddenfield(['name' => "accountid", 'value' => $accountId]);
            $out .= $accountIdField->show();

            if ($accountId == "new") {
                $out .= $this->makeSettingsForm(self::UI_FORM_NEWACCOUNT, [], [], $attrib);
            } else {
                $account = $this->abMgr->getAccountConfig($accountId);
                $fixedAttributes = $this->getFixedSettings($account['presetname']);
                $out .= $this->makeSettingsForm(self::UI_FORM_ACCOUNT, $account, $fixedAttributes, $attrib);
            }

            $out = $rc->requestForm(
                [
                    'task' => 'settings',
                    'action' => 'plugin.carddav.AccDetails',
                    'method' => 'post',
                ] + $attrib,
                $out
            );
        } catch (\Exception $e) {
            $logger->error($e->getMessage());
        }

        return $out;
    }

    /**
     * This function gets the account/addressbook settings from a POST request.
     *
     * The result array will only have keys set for POSTed values.
     *
     * For fixed settings of preset accounts/addressbooks, no setting values will be contained.
     *
     * @param FormSpec $formSpec Specification of the settings form
     * @param list<string> $fixedAttributes A list of non-changeable settings by choice of the admin
     * @return AccountSettings|AbookSettings An array with addressbook column keys and their setting.
     */
    private function getSettingsFromPOST(array $formSpec, array $fixedAttributes): array
    {
        $infra = Config::inst();
        $logger = $infra->logger();
        $rc = $infra->rc();

        // Fill $result with all values that have been POSTed
        $result = [];
        foreach (array_column($formSpec, 'fields') as $fields) {
            foreach ($fields as $fieldSpec) {
                [ , $fieldKey, $uiType ] = $fieldSpec;

                // Check that the attribute may be overridden
                if (in_array($fieldKey, $fixedAttributes)) {
                    continue;
                }

                $fieldValue = $rc->inputValue($fieldKey, ($uiType == 'password'));
                if (!isset($fieldValue)) {
                    continue;
                }

                // some types require data conversion / validation
                switch ($uiType) {
                    case 'plain':
                    case 'datetime':
                        // These are readonly form elements that cannot be set
                        continue 2;

                    case 'timestr':
                        try {
                            $fieldValue = Utils::parseTimeParameter($fieldValue);
                        } catch (\Exception $e) {
                            // ignore format error, keep old value
                            $logger->warning("Format error in timestring parameter $fieldKey: $fieldValue (ignored)");
                            continue 2;
                        }
                        break;

                    case 'radio':
                        $allowedValues = array_column($fieldSpec[4] ?? [], 0);
                        if (!in_array($fieldValue, $allowedValues)) {
                            // ignore not allowed value
                            $logger->warning("Not allowed value $fieldValue POSTed for $fieldKey (ignored)");
                            continue 2;
                        }
                        break;
                }

                $result[$fieldKey] = $fieldValue;
            }
        }

        /** @psalm-var AccountSettings|AbookSettings */
        return $result;
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
