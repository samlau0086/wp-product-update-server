<?php
namespace Product_Update_Server;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Plugin {
    const VERSION                 = '1.0.0';
    const OPTION_SETTINGS         = 'product_update_server_settings';
    const TRANSIENT_INDEX         = 'product_update_server_index';
    const CRON_HOOK               = 'product_update_server_refresh_index';

    /** @var Plugin */
    protected static $instance;

    /**
     * Singleton instance.
     */
    public static function instance() {
        if ( null === static::$instance ) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    protected function __construct() {
        add_action( 'init', [ $this, 'maybe_schedule_cron' ] );
        add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
        add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render_product_fields' ] );
        add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_product_fields' ] );
        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        add_action( 'admin_init', [ $this, 'handle_admin_actions' ] );
        add_action( self::CRON_HOOK, [ $this, 'refresh_index' ] );
        register_activation_hook( dirname( __DIR__ ) . '/product-update-server.php', [ $this, 'on_activation' ] );
        register_deactivation_hook( dirname( __DIR__ ) . '/product-update-server.php', [ $this, 'on_deactivation' ] );
    }

    /**
     * Register REST routes.
     */
    public function register_rest_routes() {
        register_rest_route(
            'product-update-server/v1',
            '/products',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'rest_get_products' ],
                'permission_callback' => '__return_true',
            ]
        );

        register_rest_route(
            'product-update-server/v1',
            '/products/(?P<plugin_name>[\\w\\-\\.]+)',
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ $this, 'rest_get_product' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'plugin_name'    => [
                        'required' => true,
                    ],
                    'customer_email' => [
                        'required' => false,
                    ],
                    'customer_id'    => [
                        'required' => false,
                    ],
                    'membership_plan' => [
                        'required' => false,
                    ],
                ],
            ]
        );
    }

    /**
     * REST response for product index (without download URLs).
     */
    public function rest_get_products( WP_REST_Request $request ) {
        $index = $this->get_index();

        $data = [];
        foreach ( $index as $plugin_name => $item ) {
            $data[] = [
                'plugin_name'  => $plugin_name,
                'version'      => $item['version'],
                'product_id'   => $item['product_id'],
                'last_updated' => $item['last_updated'],
            ];
        }

        return rest_ensure_response( $data );
    }

    /**
     * REST response for a single product with access validation.
     */
    public function rest_get_product( WP_REST_Request $request ) {
        $plugin_name    = sanitize_text_field( $request['plugin_name'] );
        $customer_email = sanitize_email( $request['customer_email'] );
        $customer_id    = absint( $request['customer_id'] );
        $membership     = sanitize_text_field( $request['membership_plan'] );

        $index = $this->get_index();
        if ( ! isset( $index[ $plugin_name ] ) ) {
            return new WP_Error( 'product_update_server_not_found', __( 'Requested product was not found.', 'product-update-server' ), [ 'status' => 404 ] );
        }

        if ( empty( $customer_email ) && empty( $customer_id ) && empty( $membership ) ) {
            return new WP_Error( 'product_update_server_missing_identity', __( 'Customer email, ID or membership plan is required to validate access.', 'product-update-server' ), [ 'status' => 400 ] );
        }

        $item = $index[ $plugin_name ];

        if ( ! $this->customer_has_access( $item['product_id'], $customer_email, $customer_id, $membership ) ) {
            return new WP_Error( 'product_update_server_forbidden', __( 'Customer is not authorized for this download.', 'product-update-server' ), [ 'status' => 403 ] );
        }

        return rest_ensure_response( $item );
    }

    /**
     * Check if a customer has access to the product update.
     */
    protected function customer_has_access( $product_id, $customer_email, $customer_id, $membership_plan ) {
        if ( ! empty( $customer_id ) ) {
            $user = get_user_by( 'id', $customer_id );
            if ( $user && empty( $customer_email ) ) {
                $customer_email = $user->user_email;
            }
        }

        if ( ! empty( $customer_email ) || ! empty( $customer_id ) ) {
            if ( function_exists( 'wc_customer_bought_product' ) && wc_customer_bought_product( $customer_email, $customer_id, $product_id ) ) {
                return true;
            }
        }

        if ( ! empty( $membership_plan ) && ! empty( $customer_id ) && function_exists( 'wc_memberships_is_user_active_member' ) ) {
            $plan_id = null;

            if ( function_exists( 'wc_memberships_get_membership_plan' ) ) {
                $plan = wc_memberships_get_membership_plan( $membership_plan );
                if ( $plan ) {
                    $plan_id = $plan->get_id();
                }
            }

            if ( $plan_id && wc_memberships_is_user_active_member( $customer_id, $plan_id ) ) {
                return true;
            }

            if ( wc_memberships_is_user_active_member( $customer_id, $membership_plan ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render custom fields in product data tab.
     */
    public function render_product_fields() {
        echo '<div class="options_group">';

        woocommerce_wp_text_input(
            [
                'id'          => '_plugin_name',
                'label'       => __( 'Plugin or Theme Slug', 'product-update-server' ),
                'placeholder' => 'my-plugin-slug',
                'desc_tip'    => true,
                'description' => __( 'Identifier used by the update server to match this download.', 'product-update-server' ),
                'type'        => 'text',
            ]
        );

        woocommerce_wp_text_input(
            [
                'id'          => '_version',
                'label'       => __( 'Product Version', 'product-update-server' ),
                'placeholder' => '1.0.0',
                'desc_tip'    => true,
                'description' => __( 'The current version of this product. When this changes, all update requests will be automatically cleared.', 'product-update-server' ),
                'type'        => 'text',
            ]
        );

        echo '</div>';
    }

    /**
     * Save custom product fields.
     */
    public function save_product_fields( $product ) {
        if ( isset( $_POST['_plugin_name'] ) ) {
            $product->update_meta_data( '_plugin_name', sanitize_text_field( wp_unslash( $_POST['_plugin_name'] ) ) );
        }
        if ( isset( $_POST['_version'] ) ) {
            $product->update_meta_data( '_version', sanitize_text_field( wp_unslash( $_POST['_version'] ) ) );
        }
    }

    /**
     * Retrieve cached index.
     */
    public function get_index( $force = false ) {
        $index = false;

        if ( ! $force ) {
            $index = get_transient( self::TRANSIENT_INDEX );
        }

        if ( false === $index ) {
            $index = $this->build_index();
        }

        return $index;
    }

    /**
     * Build the index and cache it.
     */
    public function build_index() {
        if ( ! function_exists( 'wc_get_products' ) ) {
            return [];
        }

        $products = wc_get_products(
            [
                'status'       => 'publish',
                'limit'        => -1,
                'downloadable' => true,
                'return'       => 'objects',
                'meta_query'   => [
                    [
                        'key'     => '_plugin_name',
                        'value'   => '',
                        'compare' => '!=',
                    ],
                ],
            ]
        );

        $index = [];
        foreach ( $products as $product ) {
            $plugin_name = $product->get_meta( '_plugin_name', true );
            if ( empty( $plugin_name ) ) {
                continue;
            }

            $downloads = $product->get_downloads();
            if ( empty( $downloads ) ) {
                continue;
            }

            $download = end( $downloads );
            if ( ! $download ) {
                continue;
            }

            $index[ $plugin_name ] = [
                'product_id'   => $product->get_id(),
                'plugin_name'  => $plugin_name,
                'version'      => $product->get_meta( '_version', true ),
                'download_url' => $download->get_file(),
                'file_name'    => $download->get_name(),
                'last_updated' => $product->get_date_modified() ? $product->get_date_modified()->date( DATE_ATOM ) : '',
            ];
        }

        $settings = $this->get_settings();
        $ttl      = isset( $settings['cache_ttl'] ) ? absint( $settings['cache_ttl'] ) : HOUR_IN_SECONDS;
        if ( $ttl <= 0 ) {
            $ttl = HOUR_IN_SECONDS;
        }

        set_transient( self::TRANSIENT_INDEX, $index, $ttl );

        update_option( self::TRANSIENT_INDEX, [
            'data'         => $index,
            'generated_at' => current_time( 'mysql' ),
        ] );

        return $index;
    }

    /**
     * Refresh index handler.
     */
    public function refresh_index() {
        delete_transient( self::TRANSIENT_INDEX );
        $this->build_index();
    }

    /**
     * Admin menu setup.
     */
    public function register_admin_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Product Update Server', 'product-update-server' ),
            __( 'Product Updates', 'product-update-server' ),
            'manage_woocommerce',
            'product-update-server',
            [ $this, 'render_settings_page' ]
        );
    }

    /**
     * Settings page rendering.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $settings   = $this->get_settings();
        $index_meta = get_option( self::TRANSIENT_INDEX );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Product Update Server', 'product-update-server' ); ?></h1>
            <?php settings_errors( 'product-update-server' ); ?>
            <form method="post">
                <?php wp_nonce_field( 'product_update_server_save_settings', 'product_update_server_nonce' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="product-update-server-cache-ttl"><?php esc_html_e( 'Cache Lifetime (seconds)', 'product-update-server' ); ?></label>
                            </th>
                            <td>
                                <input type="number" min="60" step="60" id="product-update-server-cache-ttl" name="cache_ttl" value="<?php echo esc_attr( $settings['cache_ttl'] ); ?>" />
                                <p class="description"><?php esc_html_e( 'How long the update index should be cached.', 'product-update-server' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e( 'Automatic Index Refresh', 'product-update-server' ); ?></th>
                            <td>
                                <label>
                                    <input type="checkbox" name="enable_cron" value="1" <?php checked( ! empty( $settings['enable_cron'] ) ); ?> />
                                    <?php esc_html_e( 'Enable hourly cron job to refresh the index.', 'product-update-server' ); ?>
                                </label>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p class="submit">
                    <button type="submit" name="product_update_server_action" value="save" class="button button-primary"><?php esc_html_e( 'Save Changes', 'product-update-server' ); ?></button>
                    <button type="submit" name="product_update_server_action" value="refresh" class="button"><?php esc_html_e( 'Rebuild Index Now', 'product-update-server' ); ?></button>
                </p>
            </form>
            <h2><?php esc_html_e( 'Index Status', 'product-update-server' ); ?></h2>
            <p><?php esc_html_e( 'Last generated at:', 'product-update-server' ); ?>
                <strong><?php echo isset( $index_meta['generated_at'] ) ? esc_html( $index_meta['generated_at'] ) : esc_html__( 'Never', 'product-update-server' ); ?></strong>
            </p>
            <p><?php esc_html_e( 'Cached items:', 'product-update-server' ); ?>
                <strong><?php echo isset( $index_meta['data'] ) ? esc_html( count( $index_meta['data'] ) ) : 0; ?></strong>
            </p>
        </div>
        <?php
    }

    /**
     * Handle admin form actions.
     */
    public function handle_admin_actions() {
        if ( empty( $_POST['product_update_server_action'] ) ) {
            return;
        }

        if ( empty( $_POST['product_update_server_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['product_update_server_nonce'] ) ), 'product_update_server_save_settings' ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $settings = $this->get_settings();

        if ( 'save' === $_POST['product_update_server_action'] ) {
            $settings['cache_ttl']  = isset( $_POST['cache_ttl'] ) ? absint( $_POST['cache_ttl'] ) : $settings['cache_ttl'];
            $settings['enable_cron'] = isset( $_POST['enable_cron'] ) ? 1 : 0;

            update_option( self::OPTION_SETTINGS, $settings );

            $this->maybe_schedule_cron( true );
            add_settings_error( 'product-update-server', 'settings_saved', __( 'Settings saved.', 'product-update-server' ), 'updated' );
        } elseif ( 'refresh' === $_POST['product_update_server_action'] ) {
            $this->refresh_index();
            add_settings_error( 'product-update-server', 'index_refreshed', __( 'Index rebuilt.', 'product-update-server' ), 'updated' );
        }
    }

    /**
     * Maybe schedule or unschedule cron job.
     */
    public function maybe_schedule_cron( $force = false ) {
        $settings = $this->get_settings();
        $enabled  = ! empty( $settings['enable_cron'] );
        $event    = wp_next_scheduled( self::CRON_HOOK );

        if ( $enabled && ( ! $event || $force ) ) {
            if ( $event ) {
                wp_unschedule_event( $event, self::CRON_HOOK );
            }
            wp_schedule_event( time(), 'hourly', self::CRON_HOOK );
        } elseif ( ! $enabled && $event ) {
            wp_unschedule_event( $event, self::CRON_HOOK );
        }
    }

    /**
     * Plugin activation hook.
     */
    public function on_activation() {
        $this->refresh_index();
        $this->maybe_schedule_cron( true );
    }

    /**
     * Plugin deactivation hook.
     */
    public function on_deactivation() {
        $event = wp_next_scheduled( self::CRON_HOOK );
        if ( $event ) {
            wp_unschedule_event( $event, self::CRON_HOOK );
        }
        delete_transient( self::TRANSIENT_INDEX );
    }

    /**
     * Retrieve plugin settings with defaults.
     */
    protected function get_settings() {
        $defaults = [
            'cache_ttl'  => HOUR_IN_SECONDS,
            'enable_cron' => 0,
        ];

        $settings = get_option( self::OPTION_SETTINGS, [] );
        if ( ! is_array( $settings ) ) {
            $settings = [];
        }

        return wp_parse_args( $settings, $defaults );
    }
}
