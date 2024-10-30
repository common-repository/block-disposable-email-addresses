<?php
/*
Plugin Name: Block Disposable EMail 
Plugin URI: http://wordpress.org/extend/plugins/block-disposable-email-addresses/ 
Description: This plugin protects your registered user base by preventing registration and comments with disposable email addresses (like mailinator, 10minutemail). It will stay up-to-date by using block-disposable-email.com api.
Author: Gerold Setz 
Version: 0.8
Author URI: http://www.block-disposable-email.com/
Text Domain: bdea 
Domain Path: /bdea
*/

// Check if we are running PHP5 or above, otherwise die with an error message.
function bdea_activation_check()
{
    if (version_compare(PHP_VERSION, '5.0.0', '<')) {
        deactivate_plugins(basename(__FILE__));
        wp_die("Sorry, but you can't run this plugin, it requires PHP 5 or higher.");
    }
}
register_activation_hook(__FILE__, 'bdea_activation_check');

add_action('admin_menu', 'block_dea_menu');

function block_dea_menu()
{
    add_options_page('Block DEA Options', 'Block DEA', 'manage_options', 'bdea-admin-menu', 'block_dea_options');
    add_action('admin_init', 'register_bdea_settings');
}

function plugin_section_text()
{
    echo '<p>This plugin requires an api key. You can get one at <a href="http://www.block-disposable-email.com/cms/register/" target="_bdea">www.block-disposable-email.com</a>.</p>';
    echo '<p>When subscribing for an api key you will also be asked for your servers ip address: it seems to be <i>' . $_SERVER['SERVER_ADDR'] . '</i>.</p>';
}

function plugin_section_status()
{
    /// Status abfragen
    $key     = get_option('bdea_plugin_options');
    $request = 'https://status.block-disposable-email.com/status/?apikey=' . $key['bdea_api_key'];
    
    if (!$key['bdea_api_key']) {
        echo 'No API key entered so far. Please get one and insert it here.';
    } else {
        $response = wp_remote_get($request);
        if (is_array($response)) {
            $status = json_decode($response['body']);
            //print_r($status);
            if ($status->request_status == 'ok' && $status->apikeystatus == 'active') {
                echo 'Everything fine! The API key you entered is valid. <p>Currently (as of ' . $status->credits_time . ') there are ' . number_format($status->credits) . ' credits. Especially when you use a pretty new generated api key the number might be wrong. Simply come back in about 30 minutes and check again.</p>';
                if ($status->credits <= 0)
                    echo '<p><b>Warning:</b> All your credits are used up so far! The service will now always respond with an OK message that means that disposable email addresses are not recognized until you add credits. Free credits will be added on the 1st of every month. Please consider to buy commercial credits. For further information see <a href="http://www.block-disposable-email.com/pricing.php" target="_bdea">block-disposable-email.com</a></p>';
            } else {
                echo '<div id="message" class="error">Something is wrong with your api key. The server responded with ' . $status->apikeystatus . '.</div>';
            }
        } else
            echo 'No response from server. Please try later.';
    }
}

function plugin_setting_string()
{
    
    $options = get_option('bdea_plugin_options');
    echo "<input id='plugin_text_string' name='bdea_plugin_options[bdea_api_key]' size='40' type='text' value='{$options['bdea_api_key']}' />";
}
?>

<?php
function plugin_filter_string()
{
    $options = get_option('bdea_plugin_options');
?>
<input type="radio" name="bdea_plugin_options[bdea_filter_options]" value="1" <?php
    echo ($options['bdea_filter_options'] == 1 || empty($options['bdea_filter_options']) ? 'checked' : '');
?>> All Email Interactions<br>
  <input type="radio" name="bdea_plugin_options[bdea_filter_options]" value="2" <?php
    echo ($options['bdea_filter_options'] == 2 ? 'checked' : '');
?>> Only Comments<br>
<input type="radio" name="bdea_plugin_options[bdea_filter_options]" value="3" <?php
    echo ($options['bdea_filter_options'] == 3 ? 'checked' : '');
?>> Only Registration<br><br>
<?php
}


function register_bdea_settings()
{
    register_setting('bdea_plugin_options', 'bdea_plugin_options');
    add_settings_section('plugin_status', 'Status of your API key', 'plugin_section_status', 'plugin');
    add_settings_section('plugin_main', 'Main Settings', 'plugin_section_text', 'plugin');
    add_settings_field('plugin_text_string', 'BDEA Api key', 'plugin_setting_string', 'plugin', 'plugin_main');
    add_settings_section('plugin_setting_string', 'Filter Settings', 'plugin_filter_string', 'plugin');
    
}

// Add a settings link in the plugin listing
add_filter('plugin_action_links', 'plugin_action_links', 10, 2);

function block_dea_options()
{
?>
<div>
<h2>Block Disposable Email</h2>
Options relating to the BDEA plugin.
<form action="options.php" method="post">
<?php
    settings_fields('bdea_plugin_options');
?>
<?php
    do_settings_sections('plugin');
?>

<input name="Submit" type="submit" value="<?php
    esc_attr_e('Save Changes');
?>" />
</form></div>

<?php
}

function plugin_action_links($links, $file)
{
    static $this_plugin;
    if (!$this_plugin) {
        $this_plugin = plugin_basename(__FILE__);
    }
    
    if ($file == $this_plugin) {
        $settings_link = '<a href="options-general.php?page=bdea-admin-menu">' . __('Settings') . '</a>';
        array_unshift($links, $settings_link);
    }
    
    return $links;
}

// Hooks, Funktionen
$options = get_option('bdea_plugin_options');
if ($options['bdea_filter_options'] == 1) {
    add_filter('is_email', 'bdea_check');
}

if ($options['bdea_filter_options'] == 2) {
    
    add_filter('preprocess_comment', 'bdea_block_comment');
}

if ($options['bdea_filter_options'] == 3) {
    add_filter('registration_errors', 'bdea_check_registration', 10, 3);
}

function bdea_check_registration($errors, $sanitized_user_login, $user_email)
{
    $valid_email = bdea_check($user_email);
    if (!$valid_email) {
        $errors->add('bdea_error', __('<strong>ERROR</strong>: Please use real email address.', 'my_textdomain'));
        
    }
    return $errors;
}

function bdea_block_comment($commentdata)
{
    
    $valid_email = bdea_check($commentdata['comment_author_email']);
    if (!$valid_email) {
        wp_die('Please use a valid email address');
    } else {
        return $commentdata;
    }
}

function bdea_check($email)
{
    //ini_set('default_socket_timeout',5);
    list(, $domain) = explode('@', $email);
    
    $key     = get_option('bdea_plugin_options');
    $request = 'http://check.block-disposable-email.com/easyapi/json/' . $key['bdea_api_key'] . '/' . trim($domain);
    
    //echo $request;
    $response = wp_remote_get($request, array(
        'timeout' => 7
    ));
    if (is_array($response)) {
        $dea = json_decode($response['body']);
        
        if (in_array($dea->request_status, array(
            'success',
            'fail_key_low_credits',
            'fail_parameter_count',
            'fail_server',
            'fail_key'
        ))) {
            if ($dea->domain_status == 'ok') {
                // do something like register ...
                return true;
            }
            
            if ($dea->domain_status == 'block') {
                // deny registration ...
                return false;
            }
        } else
            return false;
    } else
        return true;
}

// Message after activation

/**
 * Generic function to show a message to the user using WP's
 * standard CSS classes to make use of the already-defined
 * message colour scheme.
 *
 * @param $message The message you want to tell the user.
 * @param $errormsg If true, the message is an error, so use
 * the red message style. If false, the message is a status
 * message, so use the yellow information message style.
 */
function showActivationMessage($message, $errormsg = true)
{
    if ($errormsg) {
        echo '<div id="message" class="error">';
    } else {
        echo '<div id="message" class="updated fade">';
    }
    
    $message = '<h3>Warning from the Block Disposable E-Mail Plugin </h3><p>Please insert a valid api key!</p><p>The plugin will not work correctly otherwise ...</p><p>You can get one at <a href="http://www.block-disposable-email.com/cms/register/" target="_bdea">www.block-disposable-email.com</a>.</p>';
    echo "<p><strong>$message</strong></p></div>";
}

$key = get_option('bdea_plugin_options');
if (!$key['bdea_api_key'])
    add_action('admin_notices', 'showActivationMessage');


?>
