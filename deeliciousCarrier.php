
<?php

if (!defined('_PS_VERSION_'))
    exit;

class MyModule extends CarrierModule {

    public function __construct() {
        $this->name = 'mymodule';
        $this->tab = 'shipping_logistics';
        $this->version = '1.0';
        $this->author = 'Murtaza Hussain';
        $this->need_instance = 1;
        //$this->ps_versions_compliancy = array('min' => '1.5', 'max' => '1.5');
        //$this->dependencies = array('blockcart');

        parent::__construct();

        $this->displayName = $this->l('Deelicious Carrier');
        $this->description = $this->l('Driving Distance based delivery module, murtaza.hussain@converget.com');

        $this->confirmUninstall = $this->l('Are you sure you want to uninstall?');

        if (!Configuration::get('MYMODULE_NAME'))
            $this->warning = $this->l('No name provided');

        if (self::isInstalled($this->name)) {
            // Getting carrier list
            global $cookie;
            $carriers = Carrier::getCarriers($cookie->id_lang, true, false, false, NULL, ALL_CARRIERS);

            // Saving id carrier list
            $id_carrier_list = array();
            foreach ($carriers as $carrier)
                $id_carrier_list[] .= $carrier['id_carrier'];

            // Testing if Carrier Id exists
            $warning = array();
            if (!in_array((int) (Configuration::get('MYCARRIER1_CARRIER_ID')), $id_carrier_list))
                $warning[] .= $this->l('"Carrier 1"') . ' ';

            if (count($warning))
                $this->warning .= implode(' , ', $warning) . $this->l('must be configured to use this module correctly') . ' ';
        }
    }

    public function install() {
        if (Shop::isFeatureActive())
            Shop::setContext(Shop::CONTEXT_ALL);

        $carrierConfig = array(
            0 => array('name' => 'Delivery on Door',
                'id_tax_rules_group' => 0,
                'active' => true,
                'deleted' => 0,
                'shipping_handling' => false,
                'range_behavior' => 0,
                'delay' => array('en' => 'Description 1', Language::getIsoById(Configuration::get('PS_LANG_DEFAULT')) => 'Description 1'),
                'id_zone' => 1,
                'is_module' => true,
                'shipping_external' => true,
                'external_module_name' => 'mymodule',
                'need_range' => true
            )
        );

        $id_carrier1 = $this->installExternalCarrier($carrierConfig[0]);

        Configuration::updateValue('MYCARRIER1_CARRIER_ID', (int) $id_carrier1);
        return parent::install() &&
                $this->registerHook('extraCarrier') &&
                Configuration::updateValue('MYMODULE_NAME', 'Deelicious Carrier');
    }

    public function uninstall() {
        // Uninstall
        if (!parent::uninstall() ||
                !Configuration::deleteByName('MYMODULE_NAME') ||
                !$this->registerHook('extraCarrier'))
            return false;

        // Delete External Carrier
        $Carrier1 = new Carrier((int) (Configuration::get('MYCARRIER1_CARRIER_ID')));

        // If external carrier is default set other one as default
        if (Configuration::get('PS_CARRIER_DEFAULT') == (int) ($Carrier1->id)) {
            global $cookie;
            $carriersD = Carrier::getCarriers($cookie->id_lang, true, false, false, NULL, PS_CARRIERS_AND_CARRIER_MODULES_NEED_RANGE);
            foreach ($carriersD as $carrierD)
                if ($carrierD['active'] AND !$carrierD['deleted'] AND ($carrierD['name'] != $this->_config['name']))
                    Configuration::updateValue('PS_CARRIER_DEFAULT', $carrierD['id_carrier']);
        }

        // Then delete Carrier
        $Carrier1->deleted = 1;
        if (!$Carrier1->update())
            return false;

        parent::uninstall();
        return true;
    }

    public static function installExternalCarrier($config) {
        $carrier = new Carrier();
        $carrier->name = $config['name'];
        $carrier->id_tax_rules_group = $config['id_tax_rules_group'];
        $carrier->id_zone = $config['id_zone'];
        $carrier->active = $config['active'];
        $carrier->deleted = $config['deleted'];
        $carrier->delay = $config['delay'];
        $carrier->shipping_handling = $config['shipping_handling'];
        $carrier->range_behavior = $config['range_behavior'];
        $carrier->is_module = $config['is_module'];
        $carrier->shipping_external = $config['shipping_external'];
        $carrier->external_module_name = $config['external_module_name'];
        $carrier->need_range = $config['need_range'];

        $languages = Language::getLanguages(true);
        foreach ($languages as $language) {
            if ($language['iso_code'] == 'fr')
                $carrier->delay[(int) $language['id_lang']] = $config['delay'][$language['iso_code']];
            if ($language['iso_code'] == 'en')
                $carrier->delay[(int) $language['id_lang']] = $config['delay'][$language['iso_code']];
            if ($language['iso_code'] == Language::getIsoById(Configuration::get('PS_LANG_DEFAULT')))
                $carrier->delay[(int) $language['id_lang']] = $config['delay'][$language['iso_code']];
        }

        if ($carrier->add()) {
            $groups = Group::getGroups(true);
            foreach ($groups as $group)
                Db::getInstance()->autoExecute(_DB_PREFIX_ . 'carrier_group', array('id_carrier' => (int) ($carrier->id), 'id_group' => (int) ($group['id_group'])), 'INSERT');

            $rangePrice = new RangePrice();
            $rangePrice->id_carrier = $carrier->id;
            $rangePrice->delimiter1 = '0';
            $rangePrice->delimiter2 = '10000';
            $rangePrice->add();

            $rangeWeight = new RangeWeight();
            $rangeWeight->id_carrier = $carrier->id;
            $rangeWeight->delimiter1 = '0';
            $rangeWeight->delimiter2 = '10000';
            $rangeWeight->add();

            $zones = Zone::getZones(true);
            foreach ($zones as $zone) {
                Db::getInstance()->autoExecute(_DB_PREFIX_ . 'carrier_zone', array('id_carrier' => (int) ($carrier->id), 'id_zone' => (int) ($zone['id_zone'])), 'INSERT');
                Db::getInstance()->autoExecuteWithNullValues(_DB_PREFIX_ . 'delivery', array('id_carrier' => (int) ($carrier->id), 'id_range_price' => (int) ($rangePrice->id), 'id_range_weight' => NULL, 'id_zone' => (int) ($zone['id_zone']), 'price' => '0'), 'INSERT');
                Db::getInstance()->autoExecuteWithNullValues(_DB_PREFIX_ . 'delivery', array('id_carrier' => (int) ($carrier->id), 'id_range_price' => NULL, 'id_range_weight' => (int) ($rangeWeight->id), 'id_zone' => (int) ($zone['id_zone']), 'price' => '0'), 'INSERT');
            }

            // Copy Logo
            if (!copy(dirname(__FILE__) . '/carrier.jpg', _PS_SHIP_IMG_DIR_ . '/' . (int) $carrier->id . '.jpg'))
                return false;

            // Return ID Carrier
            return (int) ($carrier->id);
        }

        return false;
    }

    public function hookupdateCarrier($params) {
        if ((int) ($params['id_carrier']) == (int) (Configuration::get('MYCARRIER1_CARRIER_ID')))
            Configuration::updateValue('MYCARRIER1_CARRIER_ID', (int) ($params['carrier']->id));
    }

    public function hookextraCarrier($params) {
        // print_r($params);
        $this->context->smarty->assign(
                array(
                    //'my_module_name' => Configuration::get('MYMODULE_NAME'),
                    //'my_module_link' => $this->context->link->getModuleLink('mymodule', 'display'),
                    'id_address' => $params['address']->id
                )
        );
        return $this->display(__FILE__, 'mymoduleShipping.tpl');
    }

    function getOrderShippingCost($params, $shipping_cost) {
        if ($this->context->customer->isLogged()) {
            $customer = new CustomerCore($this->context->customer->id);
            $customer_address = $customer->getAddresses(1);

            return (float) $this->isAddressCalculated($customer_address[0]['address1']);
        } else {
            return false;
        }
    }

    function getOrderShippingCostExternal($params) {

        if ($this->context->customer->isLogged()) {
            $customer = new CustomerCore($this->context->customer->id);
            $customer_address = $customer->getAddresses(1);
            return (float) $this->isAddressCalculated($customer_address[0]['address1']);
        } else {
            return false;
        }
    }

    public function isAddressCalculated($userAddress) {

        $meters = 0;
        $inMiles = 0;
        $totalCost = 0;

        $firstMile = Configuration::get('F_M_COST');
        $forEveryAnotherMile = Configuration::get('E_O_M_COST');
        $origin_address = Configuration::get('ORIGIN_ADDRESS');

        $url = "http://maps.googleapis.com/maps/api/distancematrix/json?origins=" . urlencode($origin_address) . "&destinations=" . urlencode($userAddress) . "&sensor=false&mode=driving";
        $jsonArr = json_decode(file_get_contents($url));
        if ($jsonArr->status != "INVALID_REQUEST" && $jsonArr->rows[0]->elements[0]->status != "ZERO_RESULTS") {
            $meters = $jsonArr->rows[0]->elements[0]->distance->value;
            $inMiles = ($meters / 1000) * 0.62137;
        }

        if ($inMiles >= 0 && $inMiles <= 1) {
            $totalCost = $inMiles * $firstMile;
        } else {
            $totalCost = (($inMiles - 1) * $forEveryAnotherMile) + $firstMile;
        }
        return $totalCost;
    }

    /*     * ***************** Form Area ************************* */

    public function getContent() {
        $output = null;
        $error = array();

        if (Tools::isSubmit('submit' . $this->name)) {
            $f_m_cost = strval(Tools::getValue('f_m_cost'));
            $e_o_m_cost = strval(Tools::getValue('e_o_m_cost'));
            $origin_address = strval(Tools::getValue('origin_address'));

            if ($f_m_cost == "") {
                $error[] = "First Mile Cost cannot be empty";
            }
            if ($e_o_m_cost == "") {
                $error[] = "Every Other Mile Cost cannot be empty";
            }
            if ($origin_address == "") {
                $error[] = "Origin Address cannot be empty";
            }

            if (count($error) == 0) {
                Configuration::updateValue('F_M_COST', $f_m_cost);
                Configuration::updateValue('E_O_M_COST', $e_o_m_cost);
                Configuration::updateValue('ORIGIN_ADDRESS', $origin_address);

                $output .= $this->displayConfirmation($this->l('Settings updated'));
            } else {
                for ($i = 0; $i < count($error); $i++) {
                    $output .= $this->displayError($this->l($error[$i]));
                }
            }
        }
        return $output . $this->displayForm();
    }

    public function displayForm() {
        // Get default Language
        $default_lang = (int) Configuration::get('PS_LANG_DEFAULT');

        // Init Fields form array
        $fields_form[0]['form'] = array(
            'legend' => array(
                'title' => $this->l('General Settings'),
            ),
            'input' => array(
                array(
                    'type' => 'text',
                    'label' => $this->l('First Mile Cost'),
                    'name' => 'f_m_cost',
                    'size' => 40,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Every Other Mile Cost'),
                    'name' => 'e_o_m_cost',
                    'size' => 40,
                    'required' => true
                ),
                array(
                    'type' => 'text',
                    'label' => $this->l('Origin Address'),
                    'name' => 'origin_address',
                    'size' => 80,
                    'required' => true
                ),
            ),
            'submit' => array(
                'title' => $this->l('Save'),
                'class' => 'button'
            )
        );

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = true;
        $helper->toolbar_scroll = true;
        $helper->submit_action = 'submit' . $this->name;
        $helper->toolbar_btn = array(
            'save' =>
            array(
                'desc' => $this->l('Save'),
                'href' => AdminController::$currentIndex . '&configure=' . $this->name . '&save' . $this->name .
                '&token=' . Tools::getAdminTokenLite('AdminModules'),
            ),
            'back' => array(
                'href' => AdminController::$currentIndex . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'desc' => $this->l('Back to list')
            )
        );

        // Load current value
        $helper->fields_value['f_m_cost'] = Configuration::get('F_M_COST');
        $helper->fields_value['e_o_m_cost'] = Configuration::get('E_O_M_COST');
        $helper->fields_value['origin_address'] = Configuration::get('ORIGIN_ADDRESS');

        return $helper->generateForm($fields_form);
    }

}
?>

