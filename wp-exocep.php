<?php
/*
Plugin Name:    WP Exocep
Description:    Exocep integration plugin.
Version:        1.2.5
Author:         Exocep
Author URI:     https://exocep.com
Text Domain:    wp-exocep
Domain Path:    /languages
*/

/**
 * Exocep plugin initialization
 */
add_action('init', 'exocep_init');
function exocep_init() {

    /**
     * Exolead shortcode
     */
    add_shortcode('exolead', 'exolead_shortcode');
    function exolead_shortcode($atts = [], $content = null) {

        // Enqueue Exocep
        wp_enqueue_style('exocep', plugins_url('css/wp-exocep.css', __FILE__));
        wp_enqueue_script('exocep', plugins_url('js/wp-exocep.js', __FILE__));
        wp_localize_script('exocep', 'ajax_var', array(
                'url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('ajax-nonce')
            )
        );

        $color = $atts['color'] ?: '#0073aa';
        $email = __('Fill in your email', 'wp-exocep');
        $question = $atts['question'] ?: __('What are your interests in our events?', 'wp-exocep');
        $button =  $atts['button'] ?: __('Follow our news', 'wp-exocep');
        $consent = $atts['consent'] ?: __('By indicating your email address above, you agree to receive our offers electronically. You can unsubscribe at any time through the unsubscribe links.', 'wp-exocep');

        $key = 'exolead_categories';

        if (false === ($categories = get_transient($key))) {
            $account = api_get('/accountFunc');

            if ($account['response']['data']) {
                $company = $account['response']['data']['company']['id'];
                $categories = implode(array_map(
                    function ($value) use ($color) {
                        return
                            '<label class="exolead--category">' .
                            '<input class="exolead--category-input" style="background: ' . $color . '" type="checkbox" name="cats[]" value="' . $value['idCategory'] . '"/>' .
                            '<span class="exolead--category-label">' . $value['label'] . '</span>' .
                            '</label>';
                    },
                    api_get('/crms/webcategories?idCompany=' . $company)['response']['data']
                ));
            }
            else {
                $categories = array();
            }
            set_transient($key, $categories, MINUTE_IN_SECONDS);
        }

        return
            '<div class="exolead">'.
                '<form class="exolead--form" data-exolead novalidate>'.
                    '<p class="exolead--description">'.$question.'</p>'.
                    $categories.
                    '<input name="email" class="exolead--email" type="text" placeholder="'.$email.'"/>'.
                    '<button class="exolead--button" style="background: '.$color.'" type="submit" disabled>'.$button.'</button>'.
                    '<p class="exolead--consent">'.$consent.'</p>'.
                '</form>'.
                '<div class="exolead--response">'.
                    '<div class="exolead--loader"></div>'.
                    '<div class="exolead--message-wrapper"><div class="exolead--message"></div></div>'.
                '</div>'.
            '</div>';
    }

    function get_endpoint() {
        $endpoint = 'http://vabe.exocep.com';

        if (get_option('exocep_api_endpoint') === 'production') {
            $endpoint = 'https://exocep.com';
        }
        return $endpoint;
    }

    function get_auth_headers() {
        return array('X-App-Exocep-Key' => get_option('exocep_api_key'));
    }

    function api_get($uri) {
        $response = wp_remote_get(get_endpoint().$uri, array('headers' => get_auth_headers()));
        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    function api_post($uri, $body) {
        $response = wp_remote_post(get_endpoint().$uri, array(
            'headers' => array_merge(get_auth_headers(), array('content-type' => 'application/json')),
            'body' => json_encode($body),
            'data_format' => 'body',
        ));
        return $response;
        return json_decode( wp_remote_retrieve_body( $response ), true );
    }

    /**
     * Exomap shortcode 
     */
    add_shortcode('exomap', 'exomap_shortcode');
    function exomap_shortcode($atts = [], $content = null) {

        // Enqueue dashicons
        wp_enqueue_style( 'dashicons' );

        // Enqueue leaflet
        wp_enqueue_style('leaflet', plugins_url('css/leaflet.css', __FILE__));
        wp_enqueue_style('leaflet-markercluster', plugins_url('css/MarkerCluster.css', __FILE__));
        wp_enqueue_style('leaflet-markercluster-default', plugins_url('css/MarkerCluster.Default.css', __FILE__));
        wp_enqueue_script('leaflet', plugins_url('js/leaflet.js', __FILE__));
        wp_enqueue_script('leaflet-markercluster', plugins_url('js/leaflet.markercluster.js', __FILE__));

        // Enqueue Exocep
        wp_enqueue_style('exocep', plugins_url('css/wp-exocep.css', __FILE__));
        wp_enqueue_script('exocep', plugins_url('js/wp-exocep.js', __FILE__));

        // Enqueue Google APIs
        wp_enqueue_script('google-apis', 'https://maps.googleapis.com/maps/api/js?libraries=places&key=AIzaSyBFUh8RwgwfP7H3o3y0AR_Z8NEBQfeghQc');

        if (isset($atts['type']) && $atts['type'] === "contact") {
            if (isset($atts['families'])) {

                $key = 'exomap_contact';

                $all = explode("|", $atts['families']);
                $families = array();

                foreach ($all as $family) {
                    $parts = explode(":", $family);
                    $name = $parts[0];
                    $label = isset($parts[1])?$parts[1]:$parts[0];
                    $families[] = array('name' => $name, 'label' => $label);
                    $key .= '_'.$name;
                }

                $includes = array();
                $excludes = array();

                if (isset($atts['categories'])) {
                    $categories = explode("|", $atts['categories']);

                    foreach ($categories as $category) {
                        if (substr($category, 0, 1) === '!') {
                            $excludes[] = substr($category, 1);
                        }
                        else {
                            $includes[] = $category;
                        }
                    }
                }

                if (false === ($markers = get_transient($key))) {
                    $markers = array();
                    foreach ($families as $family) {

                        $data = api_get('/contacts?excludeUnknownCategory=true&category='.$family['name']);

                        if ($data['response']['data']) {
                            foreach ($data['response']['data'] as $contact) {
                                if ($contact['address']['latitude'] && $contact['address']['longitude'] && !(isset($contact['npai']) && $contact['npai'] == true)) {

                                    $valid = true;
                                    $tags = isset($contact['tags'])?$contact['tags']:array();

                                    if (count($includes) > 0) {
                                      foreach ($includes as $include) {
                                          if (!in_array($include, $tags)) {
                                              $valid = false;
                                          }
                                      }
                                    }
                                    if (count($excludes) > 0) {
                                        foreach ($excludes as $exclude) {
                                            if (in_array($exclude, $tags)) {
                                                $valid = false;
                                            }
                                        }
                                    }

                                    if ($valid) {
                                        $markers[] = array(
                                            'lat' => $contact['address']['latitude'],
                                            'lng' => $contact['address']['longitude'],
                                            'name' => $contact['displayName'],
                                            'address' => $contact['address']['address3'],
                                            'zipcode' => $contact['address']['zipcode'],
                                            'city' => $contact['address']['city'],
                                            'family' => $family['name']
                                        );
                                    }
                                }
                            }
                        }
                    }
                    set_transient($key, $markers, MINUTE_IN_SECONDS);
                }
            }
        }
        return '<div class="exomap" data-exomap data-exomap-filter="'.(isset($atts['filter']) && $atts['filter'] === 'true'?'true':'false').'" data-exomap-families="'.htmlspecialchars(json_encode($families), ENT_QUOTES, 'UTF-8').'" data-exomap-markers="'.htmlspecialchars(json_encode($markers), ENT_QUOTES, 'UTF-8').'"></div>';
    }
}

/**
 * i18n
 */
add_action('plugins_loaded', 'exocep_load_textdomain');
function exocep_load_textdomain() {
    load_plugin_textdomain('wp-exocep', false, dirname(plugin_basename(__FILE__)).'/languages/');
}

/**
 * Exolead ajax follow action
 */
add_action( 'wp_ajax_exocep_follow', 'exocep_follow' );
add_action( 'wp_ajax_nopriv_exocep_follow', 'exocep_follow' );
function exocep_follow(){
    check_ajax_referer( 'ajax-nonce' );

    $account = api_get('/accountFunc');

    if ($account['response']['data']) {
        $company = $account['response']['data']['company']['idPublic'];
        $response = api_post('/public/followV2', array(
            'company' => $company,
            'email' => $_POST['email'],
            'validPattern' => '',
            'cats' => array_map(function($cat) { return 'true##'.$cat; }, $_POST['cats']),
        ));
        if ($response) {
            return wp_send_json( array('message' => __('Your request is validated. We will keep you informed about our upcoming events.', 'wp-exocep')));
        }
        else {
            return wp_send_json( array('error' => __('An error has occurred. Your request did not succeed.', 'wp-exocep')));
        }
    }
    return wp_send_json( array('error' => __('Wrong API Key', 'wp-exocep')));
}

/**
 * Admin page for Exocep settings
 */
add_action('admin_init', 'exocep_admin_init');
function exocep_admin_init() {
    register_setting('exocep', 'exocep_api_key');
    register_setting('exocep', 'exocep_api_endpoint');
}

add_action('admin_menu', 'exocep_menu');
function exocep_menu(){
    add_menu_page( 'Exocep', 'Exocep', 'manage_options', 'exocep', 'exocep_options_page', 'dashicons-location', 70);
}

function exocep_options_page() {
    echo '<div class="wrap">';
    echo '    <h1>'.esc_html( get_admin_page_title() ).'</h1>';
    echo '    <p>'.__('Configure the connection settings to Exocep.', 'wp-exocep').'</p>';

    if (isset( $_GET['settings-updated'])) {
        echo '    <div id="setting-error-settings_updated" class="updated settings-error notice is-dismissible"><p><strong>'.__('Settings saved.', 'wp-exocep').'</strong></p><button type="button" class="notice-dismiss"></button></div>';
    }

    echo '    <form method="post" action="options.php">';

    settings_fields('exocep');
    do_settings_sections('exocep');

    echo '    <table class="form-table"><tbody>';
    echo '        <tr><th scope="row"><label for="exocep_api_key">'.__('API key', 'wp-exocep').'<span class="description"> ('.__('mandatory', 'wp-exocep').')</span></label></th><td><input class="regular-text" name="exocep_api_key" type="text" id="exocep_api_key" value="'.get_option('exocep_api_key').'" aria-required="true" autocapitalize="none" autocorrect="off" maxlength="60"></td></tr>';
    echo '        <tr><th scope="row"><label for="exocep_api_endpoint">'.__('Production', 'wp-exocep').'</label></th><td><input type="checkbox" name="exocep_api_endpoint" id="exocep_api_endpoint" value="production" '.(get_option('exocep_api_endpoint') === 'production'?'checked':'').'></td></tr>';
    echo '    </tbody></table>';

    submit_button();

    echo '    </form>';
    echo '</div>';
}

?>