<?php
/**
 * Plugin Name: GiveWP Enhanced API
 * Plugin URI: https://github.com/amerkay/wp-give-api-enhanced
 * Description: Adds 5x GiveWP enhanced endpoints with full custom field and Gift Aid data. Uses the same API authentication method from GiveWP -> Tools -> API.
 * Version: 1.0.0
 * Author: Amer Kawar
 * Author URI: https://wildamer.com
 * License: GPLv2
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: givewp-api-enhanced
 * Requires PHP: 8.3
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class
 */
class GiveWP_Enhanced_API
{
    /**
     * API namespace
     *
     * This makes the endpoints available at:
     * /wp-json/give-api-enhanced/v1/donation/{id}
     * /wp-json/give-api-enhanced/v1/donor/{id}
     * /wp-json/give-api-enhanced/v1/subscription/{id}
     * /wp-json/give-api-enhanced/v1/campaign/{id}
     * /wp-json/give-api-enhanced/v1/form/{id}
     */
    const API_NAMESPACE = 'give-api-enhanced/v1';

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Get singleton instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks()
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Register REST API routes
     */
    public function register_routes()
    {
        // Register all single-resource endpoints
        $this->register_single_resource_route('donation', 'Give\Donations\Models\Donation', 'give_payment', [$this, 'format_donation_response']);
        $this->register_single_resource_route('donor', 'Give\Donors\Models\Donor', null, [$this, 'format_donor_response']);
        $this->register_single_resource_route('subscription', 'Give\Subscriptions\Models\Subscription', null);
        $this->register_single_resource_route('campaign', 'Give\Campaigns\Models\Campaign', null);
        $this->register_single_resource_route('form', 'Give\DonationForms\V2\Models\DonationForm', null);
    }

    /**
     * Register a single resource endpoint (e.g., /donation/{id}, /donor/{id})
     *
     * @param string $resource_name The resource name (e.g., 'donation', 'donor')
     * @param string $model_class Fully qualified model class name
     * @param string|null $post_type Optional WordPress post type for validation
     * @param callable|null $formatter Optional custom formatter callback
     */
    private function register_single_resource_route($resource_name, $model_class, $post_type = null, $formatter = null)
    {
        register_rest_route(
            self::API_NAMESPACE,
            '/' . $resource_name . '/(?P<id>\d+)',
            [
                'methods' => 'GET',
                'callback' => function ($request) use ($resource_name, $model_class, $post_type, $formatter) {
                    return $this->get_single_resource($request, $resource_name, $model_class, $post_type, $formatter);
                },
                'permission_callback' => [$this, 'check_permissions'],
                'args' => [
                    'id' => [
                        'required' => true,
                        'validate_callback' => function ($param) {
                            return is_numeric($param);
                        },
                    ],
                ],
            ]
        );
    }

    /**
     * Check API permissions using GiveWP authentication
     */
    public function check_permissions($request)
    {
        // Check if GiveWP is active
        if (!function_exists('give_get_option')) {
            return new WP_Error(
                'givewp_not_active',
                __('GiveWP plugin is not active.', 'givewp-api-enhanced'),
                ['status' => 503]
            );
        }

        // Get API credentials from request
        $key = $request->get_param('key');
        $token = $request->get_param('token');

        // Validate credentials
        if (empty($key) || empty($token)) {
            return new WP_Error(
                'missing_credentials',
                __('API key and token are required.', 'givewp-api-enhanced'),
                ['status' => 401]
            );
        }

        // Verify the API key and token
        if (!$this->verify_api_credentials($key, $token)) {
            return new WP_Error(
                'invalid_credentials',
                __('Invalid API key or token.', 'givewp-api-enhanced'),
                ['status' => 403]
            );
        }

        return true;
    }

    /**
     * Verify GiveWP API credentials
     */
    private function verify_api_credentials($key, $token)
    {
        if (!class_exists('Give_API')) {
            error_log('GiveWP Enhanced API: Give_API class not found');
            return false;
        }

        $api = new Give_API();

        // Get user by public key
        $user_id = $api->get_user($key);

        if (!$user_id) {
            error_log('GiveWP Enhanced API: No user found for key');
            return false;
        }

        // Get user's secret key
        $secret = $api->get_user_secret_key($user_id);

        if (empty($secret)) {
            error_log('GiveWP Enhanced API: No secret key for user ' . $user_id);
            return false;
        }

        // Validate token using GiveWP's method: md5(secret . public)
        $valid = hash_equals(md5($secret . $key), $token);

        error_log('GiveWP Enhanced API: ' . ($valid ? 'SUCCESS' : 'FAILED') . ' - User ' . $user_id);

        return $valid;
    }

    /**
     * Generic single resource getter
     *
     * @param WP_REST_Request $request
     * @param string $resource_name Resource name for error messages
     * @param string $model_class Fully qualified model class name
     * @param string|null $post_type Optional WordPress post type for validation
     * @param callable|null $formatter Optional custom formatter callback
     * @return WP_REST_Response|WP_Error
     */
    private function get_single_resource($request, $resource_name, $model_class, $post_type = null, $formatter = null)
    {
        $id = $request->get_param('id');

        // Validate post type if provided
        if ($post_type) {
            $post = get_post($id);
            if (!$post || $post->post_type !== $post_type) {
                return new WP_Error(
                    $resource_name . '_not_found',
                    sprintf(__('%s not found.', 'givewp-api-enhanced'), ucfirst($resource_name)),
                    ['status' => 404]
                );
            }
        }

        // Fetch the model
        if (!class_exists($model_class)) {
            return new WP_Error(
                'model_not_available',
                sprintf(__('%s model class not found. Please ensure GiveWP is active.', 'givewp-api-enhanced'), ucfirst($resource_name)),
                ['status' => 503]
            );
        }

        try {
            $model = $model_class::find($id);
            if (!$model) {
                return new WP_Error(
                    $resource_name . '_not_found',
                    sprintf(__('%s not found.', 'givewp-api-enhanced'), ucfirst($resource_name)),
                    ['status' => 404]
                );
            }

            // Use custom formatter if provided, otherwise use generic conversion
            $data = $formatter ? call_user_func($formatter, $model, $id) : $this->convert_model_to_array($model);

            return new WP_REST_Response(
                [
                    $resource_name => $data,
                ],
                200
            );
        } catch (Exception $e) {
            error_log("GiveWP Enhanced API: Error fetching {$resource_name} - " . $e->getMessage());
            return new WP_Error(
                'fetch_error',
                sprintf(__('Error fetching %s.', 'givewp-api-enhanced'), $resource_name),
                ['status' => 500]
            );
        }
    }

    /**
     * Format donation response with nested relationships
     */
    private function format_donation_response($donation, $donation_id)
    {
        $data = $this->convert_model_to_array($donation);

        // Add nested relationships
        $data['donor'] = $this->get_donor_data($donation->donorId);
        $data['form'] = $this->get_form_data($donation->formId);
        $data['campaign'] = $this->get_campaign_data($donation->formId);
        $data['subscription'] = $donation->subscriptionId ? $this->fetch_model('Give\Subscriptions\Models\Subscription', $donation->subscriptionId) : null;

        return $data;
    }

    /**
     * Format donor response with meta
     */
    private function format_donor_response($donor, $donor_id)
    {
        $data = $this->convert_model_to_array($donor);
        $data['meta'] = $this->get_donor_meta($donor->id);
        return $data;
    }

    /**
     * Convert GiveWP Model to array dynamically
     *
     * Handles DateTime, Money, ValueObjects, and other special types
     *
     * @param object $model The GiveWP model instance
     * @param callable|null $metaCallback Optional callback to add additional meta data
     * @return array|null
     */
    private function convert_model_to_array($model, $metaCallback = null)
    {
        if (!$model || !method_exists($model, 'getAttributes')) {
            return null;
        }

        $data = [];
        foreach ($model->getAttributes() as $key => $value) {
            // Convert DateTime objects to strings
            if ($value instanceof DateTime) {
                $data[$key] = $value->format('Y-m-d H:i:s');
            }
            // Convert Money objects to formatted amounts
            elseif (is_object($value) && get_class($value) === 'Give\Framework\Support\ValueObjects\Money') {
                $data[$key] = [
                    'amount' => $value->getAmount(),
                    'currency' => $value->getCurrency()->getCode(),
                    'formatted' => $value->formatToDecimal(),
                ];
            }
            // Convert value objects to strings (if they have __toString)
            elseif (is_object($value) && method_exists($value, '__toString')) {
                $data[$key] = (string) $value;
            }
            // Convert arrays/objects to arrays recursively
            elseif (is_array($value)) {
                $data[$key] = $value;
            } elseif (is_object($value)) {
                // For complex objects, try to serialize them
                $data[$key] = method_exists($value, 'toArray') ? $value->toArray() : (array) $value;
            }
            // Keep primitives as-is
            else {
                $data[$key] = $value;
            }
        }

        // Add additional meta if callback provided
        if ($metaCallback && is_callable($metaCallback)) {
            $additionalData = $metaCallback($model);
            if (is_array($additionalData)) {
                $data = array_merge($data, $additionalData);
            }
        }

        return $data;
    }

    /**
     * Get donor data with all custom fields
     */
    private function get_donor_data($donor_id)
    {
        return $this->fetch_model('Give\Donors\Models\Donor', $donor_id, function ($donor) {
            return ['meta' => $this->get_donor_meta($donor->id)];
        });
    }

    /**
     * Get all donor meta
     */
    private function get_donor_meta($donor_id)
    {
        global $wpdb;

        $meta = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->prefix}give_donormeta WHERE donor_id = %d",
                $donor_id
            ),
            ARRAY_A
        );

        $result = [];
        foreach ($meta as $row) {
            $value = $row['meta_value'];
            // Unserialize if needed
            if (is_serialized($value)) {
                $value = maybe_unserialize($value);
            }
            $result[$row['meta_key']] = $value;
        }

        return $result;
    }

    /**
     * Get form data with all custom fields
     */
    private function get_form_data($form_id)
    {
        return $this->fetch_model('Give\DonationForms\V2\Models\DonationForm', $form_id);
    }

    /**
     * Get campaign data by form ID
     */
    private function get_campaign_data($form_id)
    {
        if (!$form_id || !class_exists('Give\Campaigns\Models\Campaign')) {
            return null;
        }

        try {
            $campaign = \Give\Campaigns\Models\Campaign::findByFormId($form_id);
            if (!$campaign) {
                return null;
            }

            $data = $this->convert_model_to_array($campaign);
            if (method_exists($campaign, 'getGoalStats')) {
                $data['goal_stats'] = $campaign->getGoalStats();
            }
            return $data;
        } catch (Exception $e) {
            error_log('GiveWP Enhanced API: Error fetching campaign - ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generic GiveWP model fetcher
     *
     * @param string $model_class Fully qualified model class name
     * @param int $id Model ID
     * @param callable|null $meta_callback Optional callback for additional data
     * @return array|null
     */
    private function fetch_model($model_class, $id, $meta_callback = null)
    {
        if (!$id || !class_exists($model_class)) {
            return null;
        }

        try {
            $model = $model_class::find($id);
            return $model ? $this->convert_model_to_array($model, $meta_callback) : null;
        } catch (Exception $e) {
            error_log("GiveWP Enhanced API: Error fetching {$model_class} - " . $e->getMessage());
            return null;
        }
    }
}

/**
 * Initialize the plugin
 */
function givewp_enhanced_api_init()
{
    return GiveWP_Enhanced_API::get_instance();
}

// Initialize
add_action('plugins_loaded', 'givewp_enhanced_api_init');

/**
 * Activation hook
 */
register_activation_hook(
    __FILE__,
    function () {
        // Flush rewrite rules
        flush_rewrite_rules();
    }
);

/**
 * Deactivation hook
 */
register_deactivation_hook(
    __FILE__,
    function () {
        // Flush rewrite rules
        flush_rewrite_rules();
    }
);
