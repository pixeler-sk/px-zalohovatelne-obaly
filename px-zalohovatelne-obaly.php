<?php
/**
 * Plugin Name: Pixeler - Zálohovateľné obaly
 * Plugin URI: https://www.pixeler.sk/
 * Description: Doplnok umožňujúci priradenie záloh k produktom a následné prerátavanie.
 * Version: 1.0.1
 * Author: Pixeler
 * Author URI: https://www.pixeler.sk/
 * Text Domain: px-zalohovatelne-obaly
 * Domain Path: /languages
 **/


// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}


if( ! class_exists( 'Px_Updater' ) ){
    include_once( plugin_dir_path( __FILE__ ) . 'updater.php' );
}

$updater = new Px_Updater( __FILE__ );
$updater->set_username( 'pixeler-sk' );
$updater->set_repository( 'px-zalohovatelne-obaly' );
$updater->authorize( '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' );
$updater->initialize();


/* check if woocommerce is active */

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins', [])), true)) {

    add_action('admin_notices', function () {
        echo '<div class="notice notice-error"><p>Plugin Zálohovateľné obaly vyžaduje ativovaný plugin Woocommerce.</p></div>';
    });

    return;
}

new PxWcBottleDeposits();

class PxWcBottleDeposits
{

    public $backup_product_id;

    public function __construct()
    {

        $this->backup_product_id = get_option('px_wc_deposit_product_id');

        $this->hooks();

    }

    function hooks()
    {
        //Product
        add_action("add_meta_boxes", array($this, "add_deposit_meta_box"));
        add_action("save_post", array($this, "save_deposit_meta_box"), 10, 3);

        //Variants
        add_action('woocommerce_product_after_variable_attributes', array($this, 'add_variation_settings_fields'), 10, 3);
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_settings_fields'), 10, 2);
        add_filter('woocommerce_available_variation', array($this, 'load_variation_settings_fields'));

        //Cart/Checkout
        if ($this->backup_product_id) {
            add_action('woocommerce_before_calculate_totals', array($this, 'recalculate'));
        }


        add_filter('plugin_action_links_px-zalohovatelne-obaly/px-zalohovatelne-obaly.php', array($this, 'custom_plugin_links'), 10, 1);

        //Woo Settings
        add_filter('woocommerce_get_sections_products', array($this, 'add_settings_tab'));
        add_filter('woocommerce_get_settings_products', array($this, 'get_settings'), 10, 2);
    }

    function add_deposit_meta_box()
    {
        add_meta_box("deposit-metabox", "Zálohovateľné obaly", array($this, "render_deposit_metabox"), "product", "side", "high", null);
    }

    function render_deposit_metabox($post)
    {
        wp_nonce_field(basename(__FILE__), "meta-box-nonce");

        $is_backupable = get_post_meta($post->ID, 'is_backupable', true);

        $number_of_deposits = get_post_meta($post->ID, 'number_of_deposits', true);
        if (!$number_of_deposits) {
            $number_of_deposits = 1;
        }

        ?>
        <p style="display: flex;align-items: center;justify-content: space-between">
            <label for="is_backupable"><?php echo __('Zálohuje sa?', 'woocommerce') ?></label>
            <select id="is_backupable" name="is_backupable" style="width:50%;">
                <option value="0">Nie</option>
                <option <?php selected($is_backupable, 1); ?> value="1">Áno</option>
            </select>
        </p>
        <p style="display: flex;align-items: center;justify-content: space-between">
            <label for="number_of_deposits"><?php echo __('Počet záloh', 'woocommerce') ?></label>
            <input id="number_of_deposits" type="number" min="0" name="number_of_deposits" style="width:50%;" value="<?php echo $number_of_deposits; ?>"/>
        </p>

        <?php

    }

    function save_deposit_meta_box($post_id, $post, $update)
    {
        if (!isset($_POST["meta-box-nonce"]) || !wp_verify_nonce($_POST["meta-box-nonce"], basename(__FILE__)))
            return $post_id;

        if (!current_user_can("edit_post", $post_id))
            return $post_id;

        if (defined("DOING_AUTOSAVE") && DOING_AUTOSAVE)
            return $post_id;

        if ('product' != $post->post_type)
            return $post_id;


        $is_backupable = 0;
        $number_of_deposits = 1;

        if (isset($_POST['is_backupable'])) {
            $is_backupable = $_POST['is_backupable'];
        }

        if (isset($_POST['number_of_deposits'])) {
            $number_of_deposits = $_POST['number_of_deposits'];
        }

        update_post_meta($post_id, 'is_backupable', $is_backupable);
        update_post_meta($post_id, 'number_of_deposits', $number_of_deposits);

    }

    //Variants
    function add_variation_settings_fields($loop, $variation_data, $variation)
    {
        woocommerce_wp_text_input(
            array(
                'id' => "number_of_deposits{$loop}",
                'name' => "number_of_deposits[{$loop}]",
                'value' => get_post_meta($variation->ID, 'number_of_deposits', true),
                'label' => __('Počet záloh', 'woocommerce'),
                'desc_tip' => true,
                'description' => __('Zdajte počet vrátnych obalov.', 'woocommerce'),
                'wrapper_class' => 'form-row form-row-full',
            )
        );
    }

    function save_variation_settings_fields($variation_id, $loop)
    {
        $number_of_deposits = $_POST['number_of_deposits'][$loop];

        if (!empty($number_of_deposits)) {
            update_post_meta($variation_id, 'number_of_deposits', esc_attr($number_of_deposits));
        }
    }

    function load_variation_settings_fields($variation)
    {
        $variation['number_of_deposits'] = get_post_meta($variation['variation_id'], 'number_of_deposits', true);

        return $variation;
    }

    //Cart/Checkout
    function recalculate($cart)
    {

        global $woocommerce;

        if (is_admin() && !defined('DOING_AJAX'))
            return;

        if (did_action('woocommerce_before_calculate_totals') >= 2)
            return;


        if (!WC()->cart->is_empty()) {

            $total_deposits = 0;

            foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) {

                $product_id = $cart_item['product_id'];

                $deposit = get_post_meta($product_id, 'is_backupable', true);

                if ($cart_item['variation_id']) {
                    $number_of_deposits = get_post_meta($cart_item['variation_id'], 'number_of_deposits', true);
                } else {
                    $number_of_deposits = get_post_meta($product_id, 'number_of_deposits', true);
                }


                if (!$number_of_deposits) {
                    $number_of_deposits = 1;
                }

                if (!empty($deposit) && $deposit == 1) {
                    $total_deposits = $total_deposits + $number_of_deposits * $cart_item['quantity'];
                }

                if ($cart_item['product_id'] == $this->backup_product_id) {
                    WC()->cart->remove_cart_item($cart_item_key);
                }


            }

            // podla poctu depositch produktov sa pridaju jednotlive poplatky
            if ((WC()->customer->get_billing_country() == 'SK' && is_checkout()) || !is_checkout()) {

                if ($total_deposits > 0) {
                    WC()->cart->add_to_cart($this->backup_product_id, $total_deposits);
                }
            }
        }
    }

    /*
     * ADMIN
     */

    function custom_plugin_links($links)
    {
        $links['settings'] = '<a href="' . admin_url('admin.php?page=wc-settings&tab=products&section=px_zalohovatelne_obaly') . '">' . __('Nastavenia', 'px-zalohovatelne-obaly') . '</a>';

        return $links;
    }


    //Woo Settings
    function add_settings_tab($settings_tab)
    {
        $settings_tab['px_zalohovatelne_obaly'] = __('Zálohovateľné obaly');
        return $settings_tab;

    }

    function get_settings($settings, $current_section)
    {
        $custom_settings = array();

        if ('px_zalohovatelne_obaly' == $current_section) {

            $custom_settings = array(
                array(
                    'name' => __('Zálohovateľné obaly'),
                    'type' => 'title',
                    //'desc' => __('Zálohovateľné obaly'),
                    'id' => 'backupable'
                ),
                array(
                    'title' => __('ID zálohy'),
                    'desc' => __('Zadajte ID produktu, ktorý predstavuje zálohu.'),
                    'id' => 'px_wc_deposit_product_id',
                    'default' => '',
                    'type' => 'text',
                    'desc_tip' => true,
                ),
                array(
                    'type' => 'sectionend',
                    'id' => 'backupable'
                ),

            );

            return $custom_settings;
        } else {
            return $settings;
        }

    }

}





