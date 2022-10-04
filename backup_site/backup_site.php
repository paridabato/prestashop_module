<?php
/**
* 2007-2022 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2022 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShopBundle\Install\Upgrade;

class Backup extends Module
{
    /** @var string */
    const BACKUP_SQL = 'BACKUP_SQL';

    /** @var string */
    const BACKUP_FILE = 'BACKUP_FILE';

    public $name = 'backup';

    public function __construct()
    {
        $this->name = 'backup_site';
        $this->tab = 'export';
        $this->version = '1.1.0';
        $this->author = 'Bato';
        $this->need_instance = 0;

        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Saving site files and database');
        $this->description = $this->l('Saving site files and database to an archive for further transfer or storage');

        $this->ps_versions_compliancy = array('min' => '1.6', 'max' => _PS_VERSION_);
        $this->module_key = 'fa9a33fd9f206697c96ef3d69e9906b5';
    }

    public function install()
    {
        return parent::install();
    }

    public function uninstall()
    {
        return parent::uninstall()
            && Tools::deleteDirectory(dirname(_PS_ROOT_DIR_) . '/backup')
            ;
    }

    public function getContent()
    {
        $output = '';
        /**
         * If values have been submitted in the form, process.
         */
        if (((bool)Tools::isSubmit('submitBackupModule')) == true) {
            $this->postProcess();
        }
        if (((bool)Tools::isSubmit('submitAddmoduleBackup')) == true) {
            $output .= $this->postBackup();
        }
        if(((bool)Tools::isSubmit('restore')) == true) {
            $output .= $this->postRestore();
        }
        if(((bool)Tools::isSubmit('delete')) == true) {
            $output .= $this->postDelete();
        }
        if(((bool)Tools::isSubmit('telecharger')) == true) {
            $output .= $this->postTelecharger();
        }

        return $output . $this->renderForm() . $this->renderList();
    }

    protected function postTelecharger(){
        $file = Tools::getValue('file');
        if (file_exists($file)) {
            if (ob_get_level()) {
                ob_end_clean();
            }
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename=' . basename($file));
            header('Content-Transfer-Encoding: binary');
            header('Expires: 0');
            header('Cache-Control: must-revalidate');
            header('Pragma: public');
            header('Content-Length: ' . filesize($file));
            if ($fd = fopen($file, 'rb')) {
                while (!feof($fd)) {
                    print fread($fd, 1024);
                }
                fclose($fd);
            }
            exit;
        }
    }

    protected function postDelete(){
        $file = Tools::getValue('file');
        $return = '';
        if(Tools::deleteFile($file)){
            $return .= $this->displayConfirmation($this->l('file delete success.') . ' ('. $file .')');
        }
        return $return;
    }

    protected function postRestore()
    {
        $restore = Tools::getValue('restore');

        $parametersFilepath = _PS_ROOT_DIR_  . '/app/config/parameters.php';
        if (!file_exists($parametersFilepath)) {
            if (Upgrade::migrateSettingsFile() === false) {
                return;
            }
        }
        $parameters = require $parametersFilepath;
        if (!array_key_exists('parameters', $parameters)) {
            throw new \Exception('Missing "parameters" key in "parameters.php" configuration file');
        }

        $array = explode('/', $restore);
        $array1 = explode('_', array_pop($array));
        $type = array_shift($array1);
        $return = '';
        $output = [];
        if($type == 'mysql'){
            $command_mysql = 'gunzip < '.$restore.' | mysql -u '
                .$parameters['parameters']['database_user'].' -p'
                .$parameters['parameters']['database_password'].' '
                .$parameters['parameters']['database_name'];
            exec($command_mysql, $output, $result_code);
            if(!$result_code)
                $return .= $this->displayConfirmation($this->l('mysql restore success.') . ' ('. $restore .')');
        }
        else if($type == 'file'){
            $command_del = 'rm -rf '._PS_ROOT_DIR_.'/.* '._PS_ROOT_DIR_.'/*';
            exec($command_del, $output, $result_code);
            $command_tar = 'tar xvf ' . $restore . ' -C /';
            exec($command_tar, $output, $result_code);
            if(!$result_code)
                    $return .= $this->displayConfirmation($this->l('file restore success.') . ' ('. $restore .')');
        }
        else $return .= $this->displayError($this->l('Unknown type') . ' ('. $type .')');

        return $return;
    }

    public function getDate($date_str){
        $date_1 = explode('_', $date_str['file']);
        if(isset($date_1['2']))
            $date_2 = $date_1[1] . ' '. explode('.', $date_1['2'])[0];
        else $date_2 = explode('.', $date_1['1'])[0];

        return strtotime($date_2);
    }

    public function cmp($a, $b) {
        $date_a = $this->getDate($a);
        $date_b = $this->getDate($b);
        if ($date_a == $date_b) {
            return 0;
        }
        return ($date_a < $date_b) ? 1 : -1;
    }

    public function renderList()
    {
        if(!file_exists(dirname(_PS_ROOT_DIR_) . '/backup'))
            mkdir(dirname(_PS_ROOT_DIR_) . '/backup',0777, true);
        $links = array_diff(scandir(dirname(_PS_ROOT_DIR_) . '/backup'), ['.', '..']);
        $files_arr = [];
        foreach ($links as $dir){
            $files = array_diff(scandir(dirname(_PS_ROOT_DIR_) . '/backup/' . $dir), ['.', '..']);
            foreach ($files as $file){
                $q = explode('_', $file);
                $w = explode('.', $q[1]);
                if(isset($q[2])){
                    $wh = explode('.', $q[2]);
                }
                $files_arr[$file] = [
                    'type' => $q[0],
                    'date' => $w[0] . (isset($wh)?' ' . $wh[0]:''),
                    'file' => dirname(_PS_ROOT_DIR_) . '/backup/' . $dir . '/' . $file
                ];
            }
        }
        uasort($files_arr, [$this, 'cmp']);

        $fields_list = array(
            'type' => array(
                'title' => $this->l('Type backup'),
                'align' => 'center',
                'type' => 'select',
                'select' => $files_arr,
                'list' => ['file', 'mysql'],
                'filter_key' => 'type'
            ),
            'date' => array(
                'title' => $this->l('Date'),
                'align' => 'center',
                'type' => 'datetime',
            ),
            'file' => array(
                'title' => $this->l('File'),
                'align' => 'center',
            ),
        );

        $helper = new HelperList();
        $helper->bulk_actions = false;
        $helper->shopLinkType = '';
        $helper->no_link = true;
        $helper->simple_header = true;
        $helper->actions = ['telecharger', 'restore', 'delete'];
        $helper->bulk_actions = false;
        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->title = $this->l('Backup list');
        $helper->identifier = 'file';
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex.'&configure='.$this->name;

        return $helper->generateList($files_arr, $fields_list);
    }

    public function displayDeleteLink($token, $id, $name = null){
        $helper = new HelperList();
        $tpl = $helper->createTemplate('list_action_delete.tpl');

        $href = AdminController::$currentIndex.'&configure='.$this->name . '&file=' . $id . '&delete&token=' . ($token != null ? $token : $this->token);
        $tpl->assign([
            'href' => $href,
            'action' => 'Delete',
            'confirm' => $this->l('Delete selected item?')
        ]);
        return $tpl->fetch();
    }

    public function displayTelechargerLink($token, $id, $name = null){
        $helper = new HelperList();

        $tpl = $helper->createTemplate('list_action_default.tpl');

        $href = AdminController::$currentIndex.'&configure='.$this->name . '&file=' . $id . '&telecharger&token=' . ($token != null ? $token : $this->token);
        $tpl->assign([
            'href' => $href,
            'action' => 'Télécharger',
        ]);
        return $tpl->fetch();
    }

    public function displayRestoreLink($token, $id, $name = null){
        $helper = new HelperList();
        $helper->override_folder = false;
        $helper->module = $this;
        $tpl = $helper->createTemplate('list_action_restore.tpl');

        $href = AdminController::$currentIndex.'&configure='.$this->name . '&restore=' . $id . '&token=' . ($token != null ? $token : $this->token);
        $tpl->assign([
            'href' => $href,
            'action' => 'Restore',
            'confirm' => $this->l('Restore backup?')
        ]);
        return $tpl->fetch();
    }

    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitBackupModule';
//        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
//            .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    protected function getConfigForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                'title' => $this->l('Settings'),
                'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'col' => 3,
                        'type' => 'text',
                        'prefix' => '<i class="icon icon-envelope"></i>',
                        'desc' => $this->l('Enter a valid email address'),
                        'name' => 'BACKUP_EMAIL',
                        'label' => $this->l('Email'),
                    ),
                    [
                        'type' => 'switch',
                        'label' => $this->trans(
                            'Sauvegarder la base de données',
                            [],
                            'Modules.Buckup.Admin'
                        ),
                        'desc' => $this->trans(
                            "Sélectionnez Oui si vous souhaitez conserver la base de données.",
                            [],
                            'Modules.Buckup.Admin'
                        ),
                        'name' => self::BACKUP_SQL,
                        'is_bool' => true,
                        'required' => true,
                        'values' => [
                            [
                                'id' => self::BACKUP_SQL . '_on',
                                'value' => 1,
                                'label' => $this->trans('Enabled', [], 'Admin.Global')
                            ],
                            [
                                'id' => self::BACKUP_SQL . '_off',
                                'value' => 0,
                                'label' => $this->trans('Disabled', [], 'Admin.Global')
                            ]
                        ]
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans(
                            "Sauvegarde de fichiers",
                            [],
                            'Modules.Buckup.Admin'
                        ),
                        'desc' => $this->trans(
                            'Sélectionnez Oui si vous souhaitez conserver les fichiers.',
                            [],
                            'Modules.Buckup.Admin'
                        ),
                        'name' => self::BACKUP_FILE,
                        'is_bool' => true,
                        'required' => true,
                        'values' => [
                            [
                                'id' => self::BACKUP_FILE . '_on',
                                'value' => 1,
                                'label' => $this->trans('Enabled', [], 'Admin.Global')
                            ],
                            [
                                'id' => self::BACKUP_FILE . '_off',
                                'value' => 0,
                                'label' => $this->trans('Disabled', [], 'Admin.Global')
                            ]
                        ]
                    ]
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
                'buttons' => array(
                    'backup' => array(
                        'title' => $this->l('Backup'),
                        'name' => 'submitAdd'.$this->table.'Backup',
                        'type' => 'submit',
                        'class' => 'btn btn-success pull-left'
                    )
                )
            ),
        );
    }

    protected function getConfigFormValues()
    {
        return array(
            'BACKUP_EMAIL' => Configuration::get('BACKUP_EMAIL'),
            'BACKUP_SQL' => Configuration::get('BACKUP_SQL'),
            'BACKUP_FILE' => Configuration::get('BACKUP_FILE'),
        );
    }

    protected function postProcess()
    {
        $form_values = $this->getConfigFormValues();

        foreach (array_keys($form_values) as $key) {
            if(!empty( Tools::getValue($key)))
                Configuration::updateValue($key, Tools::getValue($key));
            else Configuration::deleteByName($key);
        }
    }

    protected function postBackup(){
        $return = '';
        $output = [];

        if(Configuration::get('BACKUP_SQL')){
            $parametersFilepath = _PS_ROOT_DIR_  . '/app/config/parameters.php';
            if (!file_exists($parametersFilepath)) {
                if (Upgrade::migrateSettingsFile() === false) {
                    return;
                }
            }
            $parameters = require $parametersFilepath;
            if (!array_key_exists('parameters', $parameters)) {
                throw new \Exception('Missing "parameters" key in "parameters.php" configuration file');
            }
            $date = new \DateTime;
            $date_dir = $date->format('Y-m-d');
            $date_file = $date->format('Y-m-d_H:i:s');
            if(!file_exists(dirname(_PS_ROOT_DIR_) . '/backup/'.$date_dir))
                mkdir(dirname(_PS_ROOT_DIR_) . '/backup/'.$date_dir,0777, true);
            $command_mysqldump = 'mysqldump -u '
                .$parameters['parameters']['database_user'].' -p'
                .$parameters['parameters']['database_password'].' '
                .$parameters['parameters']['database_name'].' | gzip > '
                . dirname(_PS_ROOT_DIR_) . '/backup/'.$date_dir.'/mysql_'.$date_file.'.sql.gz';
            exec($command_mysqldump, $output, $result_code);
            if(!$result_code)
                $return .= $this->displayConfirmation($this->l('mysql dump success.') . ' ('. dirname(_PS_ROOT_DIR_) . '/backup/'.$date_dir.'/mysql_'.$date_file.'.sql.gz)');
        }

        if(Configuration::get('BACKUP_FILE')){
            $command_tar = 'tar cvzf ' . dirname(_PS_ROOT_DIR_) . '/backup/'.$date_dir.'/file_'.$date_file.'.tar.gz '._PS_ROOT_DIR_;
            exec($command_tar, $output, $result_code);
            if(!$result_code)
                $return .= $this->displayConfirmation($this->l('file dump success.') . ' ('. dirname(_PS_ROOT_DIR_) . '/backup/'.$date_dir.'/file_'.$date_file.'.sql.gz)');

        }
        if(Configuration::get('BACKUP_EMAIL')){
            $language = new Language($this->context->language->id);
            $file_attachment = [];
            if(Configuration::get('BACKUP_SQL')){
                $file_attachment[] = [
                    fopen(dirname(_PS_ROOT_DIR_) . '/backup/'.$date_dir.'/mysql_'.$date_file.'.sql.gz', 'r'),
                    'application/x-gzip',
                    'mysql_'.$date_file.'.sql.gz'
                ];
            }
            if(Configuration::get('BACKUP_FILE')){
                $file_attachment[] = [
                    fopen(dirname(_PS_ROOT_DIR_) . '/backup/'.$date_dir.'/file_'.$date_file.'.tar.gz', 'r'),
                    'application/x-gzip',
                    'file_'.$date_file.'.sql.gz'
                ];
            }
            $mail = Mail::send(
                $this->context->language->id,
                'backup_email',
                $this->trans(
                    'Backup site',
                    [],
                    'Emails.Subject',
                    $language->locale
                ),
                [],
                Configuration::get('BACKUP_EMAIL'),
                null,
                null,
                null,
                $file_attachment,
                null,
                dirname(__FILE__) . '/mails/',
                false,
                $this->context->shop->id
            );
            if($mail){
                $return .= $this->displayConfirmation($this->l('mail send success.') . ' ('. Configuration::get('BACKUP_EMAIL') .')');
            }
        }

        return $return;
    }
}
