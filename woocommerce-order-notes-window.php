<?php
/*
Plugin Name: WooCommerce Order Notes Window
Plugin URI: https://wordpress.org/plugins/woocommerce-order-notes-window/
Description: Overrides the default behaviour when clicking on Order notes button on Orders page so that modal window with order notes is displayed instead of navigating user to the order page.
Author: Rene Puchinger
Version: 1.0.1
Author URI: https://profiles.wordpress.org/rene-puchinger/
License: GPL3

		Copyright (C) 2013  Rene Puchinger

		This program is free software: you can redistribute it and/or modify
		it under the terms of the GNU General Public License as published by
		the Free Software Foundation, either version 3 of the License, or
		(at your option) any later version.

		This program is distributed in the hope that it will be useful,
		but WITHOUT ANY WARRANTY; without even the implied warranty of
		MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
		GNU General Public License for more details.

		You should have received a copy of the GNU General Public License
		along with this program.  If not, see <http://www.gnu.org/licenses/>.

*/

if (!defined('ABSPATH')) exit; // Exit if accessed directly

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) return; // Check if WooCommerce is active

if (!class_exists('WooCommerce_Order_Notes_Window_t4m')) {

	class WooCommerce_Order_Notes_Window_t4m {

		public function __construct() {
			$this->current_tab = (isset($_GET['tab'])) ? $_GET['tab'] : 'general';

			$this->settings_tabs = array(
				'order_notes_window' => __('Order Notes Window', 'wc_order_notes_window')
			);

			add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'action_links'));

			add_action('woocommerce_settings_tabs', array($this, 'add_tab'), 10);

			foreach ($this->settings_tabs as $name => $label) {
				add_action('woocommerce_settings_tabs_' . $name, array($this, 'settings_tab_action'), 10);
				add_action('woocommerce_update_options_' . $name, array($this, 'save_settings'), 10);
			}

			add_action('woocommerce_order_notes_window_settings', array($this, 'add_settings_fields'), 10);
			add_action('manage_shop_order_posts_custom_column', array($this, 'custom_order_columns'), 10);
			add_action('admin_enqueue_scripts', array($this, 'enqueue_dependencies_admin'));
			remove_action('wp_ajax_woocommerce_add_order_note', array($this, 'woocommerce_add_order_note'));
			add_action('wp_ajax_woocommerce_add_order_note', array($this, 'custom_add_order_note'));
			add_action('wp_insert_comment', array($this, 'custom_wp_insert_comment'), 10, 2);
		}

		/**
		 * Add action links under WordPress > Plugins
		 *
		 * @param $links
		 * @return array
		 */
		public function action_links($links) {

			$plugin_links = array(
				'<a href="' . admin_url('admin.php?page=woocommerce&tab=order_notes_window') . '">' . __('Settings', 'woocommerce') . '</a>',
			);

			return array_merge($plugin_links, $links);
		}

		protected function order_columns_script($post) {
			global $woocommerce;
			?>

			<script
				type='text/javascript'>jQuery('#the-list #post-<?php echo $post->ID; ?>').find('.order_comments a.post-com-count').attr("href", "#TB_inline?width=600&height=550&inlineId=order-notes-<?php echo $post->ID; ?>").addClass("thickbox");

				jQuery(function () {

					jQuery('#order-notes-inside-<?php echo $post->ID; ?>')

						.on('click', 'a.add_note', function () {

							if (!jQuery('#order-notes-inside-<?php echo $post->ID; ?> textarea#add_order_note').val()) return;

							jQuery('#order-notes-inside-<?php echo $post->ID; ?>').block({ message: null, overlayCSS: { background: '#fff url(<?php echo $woocommerce->plugin_url(); ?>/assets/images/ajax-loader.gif) no-repeat center', opacity: 0.6 } });

							var data = {
								action: 'woocommerce_add_order_note',
								post_id: '<?php echo $post->ID; ?>',
								note: jQuery('#order-notes-inside-<?php echo $post->ID; ?> textarea#add_order_note').val(),
								note_type: jQuery('#order-notes-inside-<?php echo $post->ID; ?> select#order_note_type').val(),
								security: '<?php echo wp_create_nonce("add-order-note"); ?>'
							};

							jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function (response) {

								jQuery('#order-notes-inside-<?php echo $post->ID; ?> ul.order_notes').prepend(response);
								jQuery('#order-notes-inside-<?php echo $post->ID; ?>').unblock();
								jQuery('#order-notes-inside-<?php echo $post->ID; ?> #add_order_note').val('');

								countEl = jQuery('#the-list #post-<?php echo $post->ID; ?>').find('.comment-count');
								countEl.html(parseInt(countEl.html()) + 1);

							});

							return false;

						})

						.on('click', 'a.delete_note', function () {

							var note = jQuery(this).closest('li.note');

							jQuery(note).block({ message: null, overlayCSS: { background: '#fff url(<?php echo $woocommerce->plugin_url(); ?>/assets/images/ajax-loader.gif) no-repeat center', opacity: 0.6 } });

							var data = {
								action: 'woocommerce_delete_order_note',
								note_id: jQuery(note).attr('rel'),
								security: '<?php echo wp_create_nonce("delete-order-note"); ?>'
							};

							jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', data, function (response) {

								jQuery(note).remove();

								countEl = jQuery('#the-list #post-<?php echo $post->ID; ?>').find('.comment-count');
								countEl.html(parseInt(countEl.html()) - 1);

							});

							return false;

						});

				});

			</script>

		<?php

		}

		public function custom_order_columns($column) {
			if ($column == 'order_comments') {

				global $post;

				$this->order_columns_script($post);

				?>

				<div id="order-notes-<?php echo $post->ID; ?>" style="display:none;">
					<div>
						<div id="order-notes-inside-<?php echo $post->ID; ?>">
							<?php $this->show_note($post->ID); ?>
						</div>
					</div>
				</div>
			<?php

			}

		}

		protected function show_note($id) {

			global $woocommerce;

			$args = array(
				'post_id' => $id,
				'approve' => 'approve',
				'type' => 'order_note'
			);

			$notes = get_comments($args);

			echo '<ul class="order_notes">';

			if ($notes) {
				foreach ($notes as $note) {
					$note_classes = get_comment_meta($note->comment_ID, 'is_customer_note', true) ? array('customer-note', 'note') : array('note');

					?>
					<li rel="<?php echo absint($note->comment_ID); ?>"
					    class="<?php echo implode(' ', $note_classes); ?>">
						<div class="note_content">
							<?php echo wpautop(wptexturize(wp_kses_post($note->comment_content))); ?>
						</div>
						<p class="meta">
							<?php printf(__('added %s ago', 'woocommerce'), human_time_diff(strtotime($note->comment_date_gmt), current_time('timestamp', 1))); ?>
							by <?php echo $note->comment_author; ?>
							<a href="#" class="delete_note"><?php _e('Delete note', 'woocommerce'); ?></a>
						</p>
					</li>
				<?php
				}
			} else {
				echo '<li>' . __('There are no notes for this order yet.', 'woocommerce') . '</li>';
			}

			echo '</ul>';

			?>


			<div class="add_note">
				<h4><?php _e('Add note', 'woocommerce'); ?> <img class="help_tip"
				                                                 data-tip='<?php esc_attr_e('Add a note for your reference, or add a customer note (the user will be notified).', 'woocommerce'); ?>'
				                                                 src="<?php echo $woocommerce->plugin_url(); ?>/assets/images/help.png"
				                                                 height="16" width="16"/></h4>

				<p>
					<textarea type="text" name="order_note" id="add_order_note" class="input-text" cols="20"
					          rows="5"></textarea>
				</p>

				<p>
					<select name="order_note_type" id="order_note_type">
						<option
							value="customer" <?php if (get_option('t4m_default_note_type') == 'customer') echo 'selected' ?>><?php _e('Customer note', 'woocommerce'); ?></option>
						<option
							value="" <?php if (get_option('t4m_default_note_type') == '') echo 'selected' ?>><?php _e('Private note', 'woocommerce'); ?></option>
					</select>
					<a href="#" class="add_note button"><?php _e('Add', 'woocommerce'); ?></a>
				</p>
			</div>

		<?php
		}

		public function custom_wp_insert_comment($id, $comment) {
			if ($comment->comment_type == 'order_note') {
				$current_user = wp_get_current_user();
				if (empty($current_user->user_firstname) && empty($current_user->user_lastname)) {
					$comment->comment_author = $current_user->user_login;
				} else {
					$comment->comment_author = $current_user->user_firstname . (!empty($current_user->user_lastname) ? " " . $current_user->user_lastname : "");
				}
				wp_update_comment(get_object_vars($comment));
			}
		}

		public function custom_add_order_note() {
			global $woocommerce;

			check_ajax_referer('add-order-note', 'security');

			$post_id = (int)$_POST['post_id'];
			$note = wp_kses_post(trim(stripslashes($_POST['note'])));
			$note_type = $_POST['note_type'];

			$is_customer_note = $note_type == 'customer' ? 1 : 0;

			if ($post_id > 0) {
				$order = new WC_Order($post_id);
				$comment_id = $order->add_order_note($note, $is_customer_note);

				echo '<li rel="' . $comment_id . '" class="note ';
				if ($is_customer_note) echo 'customer-note';
				echo '"><div class="note_content">';
				echo wpautop(wptexturize($note));
				echo '</div><p class="meta">
            by ' . get_comment_author($comment_id) . '
            <a href="#" class="delete_note">' . __('Delete note', 'woocommerce') . '</a></p>';
				echo '</li>';

			}

			// Quit out
			die();

		}

		public function enqueue_dependencies_admin() {
			wp_enqueue_script('jquery');
			wp_enqueue_style('woocommerce-order-notes-style-admin', plugins_url('assets/admin.css', __FILE__));
			add_thickbox();
		}

		/**
		 * @access public
		 * @return void
		 */
		public function add_tab() {
			foreach ($this->settings_tabs as $name => $label) {
				$class = 'nav-tab';
				if ($this->current_tab == $name)
					$class .= ' nav-tab-active';
				echo '<a href="' . admin_url('admin.php?page=woocommerce&tab=' . $name) . '" class="' . $class . '">' . $label . '</a>';
			}
		}

		/**
		 * @access public
		 * @return void
		 */
		public function settings_tab_action() {
			global $woocommerce_settings;

			// Determine the current tab
			$current_tab = $this->get_tab_in_view(current_filter(), 'woocommerce_settings_tabs_');

			// Hook onto this from another function to keep things clean.
			do_action('woocommerce_order_notes_window_settings');

			woocommerce_admin_fields($woocommerce_settings[$current_tab]);
		}

		/**
		 * Save settings in a single field in the database for each tab's fields (one field per tab).
		 */
		public function save_settings() {
			global $woocommerce_settings;

			$this->add_settings_fields();

			$current_tab = $this->get_tab_in_view(current_filter(), 'woocommerce_update_options_');
			woocommerce_update_options($woocommerce_settings[$current_tab]);
		}

		/**
		 * Get the tab current in view/processing.
		 */
		public function get_tab_in_view($current_filter, $filter_base) {
			return str_replace($filter_base, '', $current_filter);
		}


		/**
		 * Add settings fields for each tab.
		 */
		public function add_settings_fields() {
			global $woocommerce_settings;

			// Load the prepared form fields.
			$this->init_form_fields();

			if (is_array($this->fields))
				foreach ($this->fields as $k => $v)
					$woocommerce_settings[$k] = $v;
		}

		/**
		 * Prepare form fields to be used in the various tabs.
		 */
		public function init_form_fields() {

			$this->fields['order_notes_window'] = apply_filters('woocommerce_bulk_discount_settings_fields', array(

					array('name' => __('Order notes', 'wc_order_notes_window'), 'type' => 'title', 'desc' => '', 'id' => 't4m_order_notes_options'),

					array(
						'title' => __('Default note type', 'wc_order_notes_window'),
						'id' => 't4m_default_note_type',
						'desc' => __('Note type which will be selected in the Notes order modal window by default.', 'wc_order_notes_window'),
						'desc_tip' => true,
						'std' => 'yes',
						'type' => 'select',
						'css' => 'min-width:200px;',
						'class' => 'chosen_select',
						'options' => array(
							'customer' => __('Customer note', 'woocommerce'),
							'' => __('Private note', 'woocommerce')
						)
					),

					array('type' => 'sectionend', 'id' => 't4m_order_notes_options'),

					array(
						'desc' => 'If you find the WooCommerce Order Notes Window extension useful, please rate it <a target="_blank" href="http://wordpress.org/support/view/plugin-reviews/woocommerce-order-notes-window#postform">&#9733;&#9733;&#9733;&#9733;&#9733;</a>.',
						'id' => 'wc_order_notes_window_notice_text',
						'type' => 'title'
					),

					array( 'type' => 'sectionend', 'id' => 'wc_order_notes_window_notice_text' )

				)
			);

		}

	}

	$wc_order_notes_window_t4m = new WooCommerce_Order_Notes_Window_t4m();

}