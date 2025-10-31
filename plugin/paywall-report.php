<?php
/**
 * Plugin Name: Paywall Report Manager
 * Description: Управление отчетами с интеграцией с плагином Paywall PDF.
 * Version: 1.0.0
 * Author: ChatGPT
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Paywall_Report_Manager {

    const TABLE = 'pwr_reports';
    const NONCE_ACTION_ADMIN = 'pwr_admin';
    const NONCE_ACTION_FRONT = 'pwr_front';

    public static function activate() {
        global $wpdb;

        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            legal_entity VARCHAR(255) NOT NULL,
            inn VARCHAR(64) NOT NULL,
            brand VARCHAR(255) NOT NULL,
            shortcode TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY inn (inn),
            KEY brand (brand),
            KEY created_at (created_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );

        add_shortcode( 'paywall_reports', [ $this, 'render_shortcode' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_front_assets' ] );

        add_action( 'wp_ajax_pwr_admin_reports', [ $this, 'ajax_admin_reports' ] );
        add_action( 'wp_ajax_pwr_front_reports', [ $this, 'ajax_front_reports' ] );
        add_action( 'wp_ajax_nopriv_pwr_front_reports', [ $this, 'ajax_front_reports' ] );

        register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
    }

    public function register_menu() {
        add_menu_page(
            __( 'Paywall Reports', 'paywall-report' ),
            __( 'Paywall Reports', 'paywall-report' ),
            'manage_options',
            'paywall-report',
            [ $this, 'render_admin_page' ],
            'dashicons-media-spreadsheet',
            59
        );
    }

    public function enqueue_admin_assets( $hook ) {
        if ( 'toplevel_page_paywall-report' !== $hook ) {
            return;
        }

        wp_enqueue_style(
            'paywall-report-admin',
            plugins_url( 'assets/css/admin.css', __FILE__ ),
            [],
            '1.0.0'
        );

        wp_enqueue_script( 'jquery' );
        $nonce = wp_create_nonce( self::NONCE_ACTION_ADMIN );
        $data  = [
            'nonce' => $nonce,
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'limit' => 10,
        ];

        wp_register_script( 'paywall-report-admin', '', [ 'jquery' ], '1.0.0', true );
        wp_enqueue_script( 'paywall-report-admin' );
        wp_add_inline_script( 'paywall-report-admin', $this->get_admin_script( $data ), 'after' );
    }

    private function get_admin_script( $data ) {
        $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );

        return "(function($){\n  $(function(){\n    var cfg = {$json};\n    var box = $('.pwr-admin-box');\n    if(!box.length){return;}\n    var list = box.find('.pwr-list');\n    var loadBtn = box.find('.pwr-load-more');\n    var searchForm = box.find('.pwr-search-form');\n    var searchInput = searchForm.find('input[name=\"pwr_search\"]');\n    var state = {offset:0, limit:cfg.limit, loading:false, done:false, search:''};\n\n    function rowHtml(item){\n      return '<div class=\"pwr-row\">' +\n        '<div class=\"pwr-cell pwr-date\">' + item.created_at + '</div>' +\n        '<div class=\"pwr-cell pwr-entity\"><strong>' + item.legal_entity + '</strong><br><span class=\"pwr-muted\">' + item.brand + '</span></div>' +\n        '<div class=\"pwr-cell\">' + item.inn + '</div>' +\n        '<div class=\"pwr-cell\"><code>' + item.shortcode + '</code></div>' +\n      '</div>';\n    }\n\n    function setLoading(flag){\n      state.loading = flag;\n      box.toggleClass('is-loading', flag);\n      if(flag){\n        loadBtn.attr('disabled','disabled').text('Загрузка...');\n      } else {\n        loadBtn.removeAttr('disabled').text('Загрузить ещё');\n      }\n    }\n\n    function render(items, clear){\n      if(clear){\n        list.empty();\n      }\n      if(!items.length && clear){\n        list.append('<div class=\"pwr-empty\">Ничего не найдено</div>');\n      } else {\n        items.forEach(function(it){ list.append(rowHtml(it)); });\n      }\n    }\n\n    function fetchList(clear){\n      if(state.loading || (state.done && !clear)){\n        return;\n      }\n      if(clear){\n        state.offset = 0;\n        state.done = false;\n      }\n\n      setLoading(true);\n      var formData = new FormData();\n      formData.append('action','pwr_admin_reports');\n      formData.append('_wpnonce', cfg.nonce);\n      formData.append('offset', state.offset);\n      formData.append('limit', state.limit);\n      formData.append('search', state.search);\n\n      fetch(cfg.ajax, {method:'POST', body:formData})\n        .then(function(r){ return r.json(); })\n        .then(function(resp){\n          setLoading(false);\n          if(!resp || !resp.success || !resp.data){\n            window.alert('Ошибка загрузки данных');\n            return;\n          }\n          var items = Array.isArray(resp.data.items) ? resp.data.items : [];\n          render(items, clear);\n          state.offset = clear ? items.length : state.offset + items.length;\n          var hasMore = !!resp.data.has_more;\n          state.done = !hasMore;\n          if(hasMore){\n            loadBtn.show();\n          } else {\n            loadBtn.hide();\n          }\n        })\n        .catch(function(){\n          setLoading(false);\n          window.alert('Сеть недоступна');\n        });\n    }\n\n    loadBtn.on('click', function(e){\n      e.preventDefault();\n      fetchList(false);\n    });\n\n    searchForm.on('submit', function(e){\n      e.preventDefault();\n      state.search = searchInput.val().trim();\n      fetchList(true);\n    });\n\n    fetchList(true);\n  });\n})(jQuery);";
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $notice = '';
        if ( ! empty( $_POST['pwr_add_report'] ) && check_admin_referer( 'pwr_add_report', 'pwr_nonce' ) ) {
            $notice = $this->handle_create_report();
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Paywall Reports', 'paywall-report' ); ?></h1>
            <?php echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <form method="post" class="pwr-create-form">
                <?php wp_nonce_field( 'pwr_add_report', 'pwr_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="pwr_legal"><?php esc_html_e( 'Юрлицо', 'paywall-report' ); ?></label></th>
                        <td><input type="text" id="pwr_legal" name="pwr_legal" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="pwr_inn"><?php esc_html_e( 'ИНН', 'paywall-report' ); ?></label></th>
                        <td><input type="text" id="pwr_inn" name="pwr_inn" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="pwr_brand"><?php esc_html_e( 'Бренд', 'paywall-report' ); ?></label></th>
                        <td><input type="text" id="pwr_brand" name="pwr_brand" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="pwr_code"><?php esc_html_e( 'Код (шорткод)', 'paywall-report' ); ?></label></th>
                        <td><textarea id="pwr_code" name="pwr_code" class="large-text" rows="3" required></textarea></td>
                    </tr>
                </table>
                <p>
                    <button type="submit" class="button button-primary" name="pwr_add_report" value="1">
                        <?php esc_html_e( 'Добавить отчёт', 'paywall-report' ); ?>
                    </button>
                </p>
            </form>

            <hr>

            <div class="pwr-admin-box">
                <form class="pwr-search-form">
                    <input type="search" name="pwr_search" placeholder="Поиск по юрлицу, ИНН или бренду" class="pwr-search-field" />
                    <button type="submit" class="button"><?php esc_html_e( 'Искать', 'paywall-report' ); ?></button>
                </form>
                <div class="pwr-list" aria-live="polite"></div>
                <button type="button" class="button pwr-load-more"><?php esc_html_e( 'Загрузить ещё', 'paywall-report' ); ?></button>
            </div>
        </div>
        <?php
    }

    private function handle_create_report() {
        global $wpdb;

        $legal = isset( $_POST['pwr_legal'] ) ? sanitize_text_field( wp_unslash( $_POST['pwr_legal'] ) ) : '';
        $inn   = isset( $_POST['pwr_inn'] ) ? sanitize_text_field( wp_unslash( $_POST['pwr_inn'] ) ) : '';
        $brand = isset( $_POST['pwr_brand'] ) ? sanitize_text_field( wp_unslash( $_POST['pwr_brand'] ) ) : '';
        $code  = isset( $_POST['pwr_code'] ) ? wp_kses_post( wp_unslash( $_POST['pwr_code'] ) ) : '';

        if ( ! $legal || ! $inn || ! $brand || ! $code ) {
            return '<div class="notice notice-error"><p>' . esc_html__( 'Заполните все поля.', 'paywall-report' ) . '</p></div>';
        }

        $table = $wpdb->prefix . self::TABLE;
        $wpdb->insert(
            $table,
            [
                'legal_entity' => $legal,
                'inn'          => $inn,
                'brand'        => $brand,
                'shortcode'    => $code,
                'created_at'   => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( $wpdb->last_error ) {
            return '<div class="notice notice-error"><p>' . esc_html( $wpdb->last_error ) . '</p></div>';
        }

        return '<div class="notice notice-success"><p>' . esc_html__( 'Отчёт добавлен.', 'paywall-report' ) . '</p></div>';
    }

    public function enqueue_front_assets() {
        wp_register_style(
            'paywall-report-front',
            plugins_url( 'assets/css/front.css', __FILE__ ),
            [],
            '1.0.0'
        );
        wp_register_script( 'paywall-report-front', '', [], '1.0.0', true );
    }

    public function render_shortcode( $atts ) {
        global $wpdb;

        wp_enqueue_style( 'paywall-report-front' );
        wp_enqueue_script( 'paywall-report-front' );

        $limit = 5;
        $table = $wpdb->prefix . self::TABLE;
        $items = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d",
                $limit
            )
        );

        $nonce = wp_create_nonce( self::NONCE_ACTION_FRONT );
        $data  = [
            'nonce' => $nonce,
            'ajax'  => admin_url( 'admin-ajax.php' ),
            'limit' => $limit,
        ];

        wp_add_inline_script( 'paywall-report-front', $this->get_front_script( $data ), 'after' );

        ob_start();
        ?>
        <div class="pwr-shortcode" data-limit="<?php echo esc_attr( $limit ); ?>">
            <form class="pwr-search" role="search">
                <label class="screen-reader-text" for="pwr-front-search"><?php esc_html_e( 'Поиск отчётов', 'paywall-report' ); ?></label>
                <input type="search" id="pwr-front-search" name="pwr_search" placeholder="Введите ИНН, бренд или юрлицо" autocomplete="off">
                <button type="submit"><?php esc_html_e( 'Поиск', 'paywall-report' ); ?></button>
            </form>
            <div class="pwr-front-list" data-offset="<?php echo esc_attr( count( $items ) ); ?>">
                <?php $this->render_front_items( $items ); ?>
            </div>
            <button type="button" class="pwr-front-more" <?php echo count( $items ) < $limit ? 'style="display:none"' : ''; ?>><?php esc_html_e( 'Показать ещё', 'paywall-report' ); ?></button>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_front_script( $data ) {
        $json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE );

        return <<<JS
(function(){
  var cfg = {$json};
  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('.pwr-shortcode').forEach(function(box){
      var list = box.querySelector('.pwr-front-list');
      var moreBtn = box.querySelector('.pwr-front-more');
      var form = box.querySelector('.pwr-search');
      if(!list || !form){ return; }
      var input = form.querySelector('input[name="pwr_search"]');
      var state = {
        offset: parseInt(list.getAttribute('data-offset') || '0', 10) || 0,
        limit: cfg.limit,
        done: false,
        search: ''
      };
      if(state.offset < state.limit){
        state.done = true;
        if(moreBtn){ moreBtn.style.display = 'none'; }
      }

      function itemHtml(item){
        var button = item.button || '';
        return '<div class="pwr-front-item">' +
          '<div class="pwr-front-title"><strong>' + item.legal_entity + '</strong><span>' + item.brand + '</span></div>' +
          '<div class="pwr-front-inn">ИНН: ' + item.inn + '</div>' +
          '<div class="pwr-front-button">' + button + '</div>' +
        '</div>';
      }

      function setLoading(flag){
        box.classList.toggle('is-loading', flag);
        if(moreBtn){ moreBtn.disabled = !!flag; }
      }

      function render(items, clear){
        if(clear){
          list.innerHTML = '';
        }
        if(!items.length && clear){
          list.innerHTML = '<div class="pwr-front-empty">Ничего не найдено</div>';
        } else {
          items.forEach(function(it){
            list.insertAdjacentHTML('beforeend', itemHtml(it));
          });
        }
      }

      function fetchList(clear){
        if(clear){
          state.offset = 0;
          state.done = false;
        }
        if(state.done && !clear){
          return;
        }
        setLoading(true);
        var fd = new FormData();
        fd.append('action', 'pwr_front_reports');
        fd.append('nonce', cfg.nonce);
        fd.append('offset', state.offset);
        fd.append('limit', state.limit);
        fd.append('search', state.search);

        fetch(cfg.ajax, {method:'POST', body: fd})
          .then(function(r){ return r.json(); })
          .then(function(resp){
            setLoading(false);
            if(!resp || !resp.success || !resp.data){
              alert('Ошибка загрузки данных');
              return;
            }
            var items = Array.isArray(resp.data.items) ? resp.data.items : [];
            render(items, clear);
            state.offset = clear ? items.length : state.offset + items.length;
            list.setAttribute('data-offset', state.offset);
            var hasMore = !!resp.data.has_more;
            state.done = !hasMore;
            if(moreBtn){
              moreBtn.style.display = hasMore ? 'inline-flex' : 'none';
              moreBtn.disabled = false;
            }
          })
          .catch(function(){
            setLoading(false);
            alert('Сеть недоступна');
          });
      }

      if(moreBtn){
        moreBtn.addEventListener('click', function(){
          fetchList(false);
        });
      }

      form.addEventListener('submit', function(e){
        e.preventDefault();
        state.search = input ? input.value.trim() : '';
        fetchList(true);
      });
    });
  });
})();
JS;
    }
    private function render_front_items( $items ) {
        if ( empty( $items ) ) {
            echo '<div class="pwr-front-empty">' . esc_html__( 'Ничего не найдено', 'paywall-report' ) . '</div>';
            return;
        }

        foreach ( $items as $item ) {
            $this->render_front_item( $item );
        }
    }

    private function render_front_item( $item ) {
        $button = do_shortcode( $item->shortcode );
        ?>
        <div class="pwr-front-item">
            <div class="pwr-front-title">
                <strong><?php echo esc_html( $item->legal_entity ); ?></strong>
                <span><?php echo esc_html( $item->brand ); ?></span>
            </div>
            <div class="pwr-front-inn">ИНН: <?php echo esc_html( $item->inn ); ?></div>
            <div class="pwr-front-button"><?php echo $button; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div>
        </div>
        <?php
    }

    public function ajax_admin_reports() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error();
        }

        check_ajax_referer( self::NONCE_ACTION_ADMIN );

        $offset = isset( $_POST['offset'] ) ? max( 0, intval( $_POST['offset'] ) ) : 0;
        $limit  = isset( $_POST['limit'] ) ? min( 50, max( 1, intval( $_POST['limit'] ) ) ) : 10;
        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

        $data = $this->query_reports( $offset, $limit, $search );
        wp_send_json_success( $data );
    }

    public function ajax_front_reports() {
        check_ajax_referer( self::NONCE_ACTION_FRONT, 'nonce' );

        $offset = isset( $_POST['offset'] ) ? max( 0, intval( $_POST['offset'] ) ) : 0;
        $limit  = isset( $_POST['limit'] ) ? min( 20, max( 1, intval( $_POST['limit'] ) ) ) : 5;
        $search = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

        $data = $this->query_reports( $offset, $limit, $search, true );
        wp_send_json_success( $data );
    }

    private function query_reports( $offset, $limit, $search, $render_button = false ) {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;
        $where = '1=1';
        $args  = [];

        if ( $search ) {
            $like   = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare( ' AND (legal_entity LIKE %s OR inn LIKE %s OR brand LIKE %s)', $like, $like, $like );
        }

        $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $args[] = $limit + 1;
        $args[] = $offset;

        $items = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );

        $has_more = false;
        if ( count( $items ) > $limit ) {
            $has_more = true;
            array_pop( $items );
        }

        $prepared = [];
        foreach ( $items as $item ) {
            $prepared[] = [
                'id'           => (int) $item->id,
                'legal_entity' => esc_html( $item->legal_entity ),
                'inn'          => esc_html( $item->inn ),
                'brand'        => esc_html( $item->brand ),
                'shortcode'    => esc_html( $item->shortcode ),
                'created_at'   => mysql2date( 'd.m.Y H:i', $item->created_at ),
                'button'       => $render_button ? do_shortcode( $item->shortcode ) : '',
            ];
        }

        return [
            'items'    => $prepared,
            'has_more' => $has_more,
        ];
    }
}

new Paywall_Report_Manager();
