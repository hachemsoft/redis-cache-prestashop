<?php

if (!defined('_PS_VERSION_'))
	exit;



class Hsrediscache extends Module
{
	public function __construct()
	{
		$this->name = 'hsrediscache';
		$this->tab = 'front_office_features';
		$this->version = '1.0';
		$this->author = 'Hachem LATRACH';

		parent::__construct();

		$this->displayName = $this->l('Hs Redis Cache');
		$this->description = $this->l('Use Redis as cache server to give best performance to your shop');

		$this->confirmUninstall = $this->l('Are you sure you want to uninstall this module?');

		$this->_checkContent();

		$this->context->smarty->assign('module_name', $this->name);
	}

	public function install()
	{
		if (!parent::install() ||
	
			!$this->_createContent())
			return false;
		return true;
	}

	public function uninstall()
	{
		if (!parent::uninstall() ||
			!$this->_deleteContent())
			return false;
		return true;
	}


	public function getContent()
	{
		$message = '';

		if (Tools::isSubmit('submit_'.$this->name))
			$message = $this->_saveContent();

		$this->_displayContent($message);

		return $this->display(__FILE__, 'settings.tpl');
	}

	private function _saveContent()
	{
		$message = '';

		if (Configuration::updateValue('SERVEUR_REDIS', Tools::getValue('SERVEUR_REDIS')) &&
			Configuration::updateValue('PORT_REDIS', Tools::getValue('PORT_REDIS')))
			$message = $this->displayConfirmation($this->l('Your settings have been saved'));
		else
			$message = $this->displayError($this->l('There was an error while saving your settings'));

		return $message;
	}

	private function _displayContent($message)
	{
		$this->context->smarty->assign(array(
			'message' => $message,
			'SERVEUR_REDIS' => Configuration::get('SERVEUR_REDIS'),
			'PORT_REDIS' => Configuration::get('PORT_REDIS'),
		));
	}

	private function _checkContent()
	{
		if (!Configuration::get('SERVEUR_REDIS') &&
			!Configuration::get('PORT_REDIS'))
			$this->warning = $this->l('You need to configure this module.');
	}

	private function _createContent()
	{
		if (!Configuration::updateValue('SERVEUR_REDIS', '') ||
			!Configuration::updateValue('PORT_REDIS', ''))
			return false;
		return true;
	}

	private function _deleteContent()
	{
		if (!Configuration::deleteByName('SERVEUR_REDIS') ||
			!Configuration::deleteByName('PORT_REDIS'))
			return false;
		return true;

	}
}

?>
