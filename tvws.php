<?php
/**
 * Web services PrestaShop module - Perithori
 * @author    tivuno.com <hi@tivuno.com>
 * @copyright 2018 - 2025 © tivuno.com
 * @license   https://tivuno.com/el/blog/nea-tis-epicheirisis/apli-adeia
 */
if (!defined('_PS_VERSION_')) {
    exit;
}
class Tvws extends Module
{
    public function __construct()
    {
        $this->name = 'tvws';
        $this->tab = 'quick_bulk_update';
        $this->version = '1.0.0';
        $this->author = 'tivuno.com';
        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => _PS_VERSION_,
        ];
        $this->bootstrap = true;
        $this->displayName = $this->l('Web services PrestaShop module - Perithori');
        $this->description = $this->l('It extends the genuine web services by adding hooks');
        parent::__construct();
    }
}
