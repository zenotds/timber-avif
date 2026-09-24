<?php

namespace TimberAVIF;

/**
 * Settings → Timber AVIF: settings, tools, and the images that need a look.
 */
final class Admin {
	const SLUG  = 'timber-avif-settings';
	const STATS = 'timber_avif_stats';

	public static function boot(): void {
		add_action('admin_menu', [self::class, 'menu']);
		add_action('admin_post_timber_avif_settings', [self::class, 'handle_settings']);
		add_action('admin_post_timber_avif_tools', [self::class, 'handle_tools']);
		add_action('wp_ajax_timber_avif_work', [self::class, 'handle_work']);
		add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
		add_filter('manage_media_columns', [self::class, 'media_column']);
		add_action('manage_media_custom_column', [self::class, 'render_media_column'], 10, 2);
	}

	public static function menu(): void {
		add_options_page('Timber AVIF', 'Timber AVIF', 'manage_options', self::SLUG, [self::class, 'render']);
	}

	private static function url(array $args = []): string {
		return add_query_arg($args, admin_url('options-general.php?page=' . self::SLUG));
	}

	/**
	 * Inline, not by URL: the package can sit in a theme, in vendor/ or behind a Composer
	 * symlink, and a URL worked out from its path broke in the last case (404). Both files
	 * are small and load on two admin screens only.
	 */
	public static function enqueue(string $hook): void {
		if ($hook !== 'upload.php' && $hook !== 'settings_page_' . self::SLUG) return;

		wp_register_style('timber-avif-admin', false, [], Plugin::VERSION);
		wp_enqueue_style('timber-avif-admin');
		wp_add_inline_style('timber-avif-admin', (string) file_get_contents(TIMBER_AVIF_DIR . '/assets/admin.css'));

		if ($hook === 'upload.php') return;

		wp_register_script('timber-avif-admin', false, [], Plugin::VERSION, true);
		wp_enqueue_script('timber-avif-admin');
		wp_add_inline_script('timber-avif-admin', 'window.timberAvif = ' . wp_json_encode([
			'ajax'  => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('timber_avif_work'),
			'i18n'  => [
				'processing' => __('Processing…', 'timber-avif'),
				'waiting'    => __('Another worker is running, waiting…', 'timber-avif'),
				/* translators: %d: number of images */
				'remaining'  => __('%d remaining', 'timber-avif'),
				'done'       => __('Queue empty.', 'timber-avif'),
				'failed'     => __('Request failed.', 'timber-avif'),
				'start'      => __('Process now', 'timber-avif'),
			],
		]) . ";\n" . (string) file_get_contents(TIMBER_AVIF_DIR . '/assets/admin.js'));
	}

	/* ─────────────────────────────────────────────
	 * Page
	 * ───────────────────────────────────────────── */

	public static function render(): void {
		if (!current_user_can('manage_options')) return;

		$tab = sanitize_key($_GET['tab'] ?? 'settings');
		$status = self::status();
		$tabs = [
			'settings' => __('Settings', 'timber-avif'),
			'tools'    => __('Tools', 'timber-avif'),
			'issues'   => __('Issues', 'timber-avif') . ($status['issues'] ? ' <span class="count">(' . number_format_i18n($status['issues']) . ')</span>' : ''),
		];
		?>
		<div class="wrap tavif-wrap">
			<div class="tavif-header">
				<h1>Timber AVIF</h1>
				<span class="tavif-version">v<?php echo esc_html(Plugin::VERSION); ?></span>
			</div>

			<?php self::render_notices(); ?>
			<?php self::render_cards($status); ?>

			<h2 class="nav-tab-wrapper">
				<?php foreach ($tabs as $key => $label) : ?>
					<a href="<?php echo esc_url(self::url(['tab' => $key])); ?>" class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>"><?php echo wp_kses($label, ['span' => ['class' => []]]); ?></a>
				<?php endforeach; ?>
			</h2>

			<div class="tavif-card">
				<?php
				if ($tab === 'tools')       self::render_tools($status);
				elseif ($tab === 'issues')  self::render_issues();
				else                        self::render_settings();
				?>
			</div>

			<p class="tavif-footer">Timber AVIF v<?php echo esc_html(Plugin::VERSION); ?> &mdash; <a href="https://github.com/zenotds/timber-avif" target="_blank" rel="noopener">GitHub</a></p>
		</div>
		<?php
	}

	private static function render_cards(array $s): void {
		$format = $s['format'];
		$engine = $format ? Engine::detect($format) : 'none';
		$gd = $engine === 'gd';

		$cards = [
			[__('Served format', 'timber-avif'), $format ? strtoupper($format) : __('Original', 'timber-avif'), $format ? 'ok' : 'off', ''],
			[__('Engine', 'timber-avif'), $format ? self::engine_label($engine) : '—', !$format ? 'off' : ($engine === 'none' ? 'fail' : ($gd ? 'warn' : 'ok')),
				$gd ? __('GD keeps no colour profile: photos in Display P3 or Adobe RGB change colour. Enable Imagick on the server.', 'timber-avif') : ''],
			[__('Library', 'timber-avif'), sprintf(__('%1$s of %2$s ready', 'timber-avif'), number_format_i18n($s['total'] - $s['pending']), number_format_i18n($s['total'])), $s['pending'] ? 'warn' : 'ok', ''],
			[__('Queue', 'timber-avif'), $s['pending'] ? sprintf(_n('%s pending', '%s pending', $s['pending'], 'timber-avif'), number_format_i18n($s['pending'])) : __('Empty', 'timber-avif'), $s['pending'] ? 'warn' : 'ok',
				$s['pending'] ? __('Converted in the background by WP-Cron and after admin requests. Tools → Process now empties it right away.', 'timber-avif') : ''],
		];
		if ($s['saved'] > 0) {
			$cards[] = [__('Saved', 'timber-avif'), self::format_bytes($s['saved']), 'ok',
				sprintf(__('%1$s converted files weigh %2$s less than the files they replace (%3$d%%).', 'timber-avif'), number_format_i18n($s['files']), self::format_bytes($s['saved']), $s['saved_pct'])];
		}
		?>
		<div class="tavif-cards">
			<?php foreach ($cards as [$label, $value, $state, $tip]) : ?>
				<div class="tavif-card-stat">
					<div class="label"><?php echo esc_html($label); ?></div>
					<div class="value">
						<span class="tavif-dot tavif-dot--<?php echo esc_attr($state); ?>"></span><?php echo esc_html($value); ?>
						<?php if ($tip) : ?><span class="dashicons dashicons-info-outline" title="<?php echo esc_attr($tip); ?>"></span><?php endif; ?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_settings(): void {
		$s = Config::all();
		$d = Config::defaults();
		$format = Config::format();
		$default = fn(string $key) => Config::is_default($key) ? '' : ' <span class="tavif-default">' . esc_html(sprintf(__('default %s', 'timber-avif'), is_bool($d[$key]) ? ($d[$key] ? __('on', 'timber-avif') : __('off', 'timber-avif')) : $d[$key])) . '</span>';
		?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<?php wp_nonce_field('timber_avif_settings'); ?>
			<input type="hidden" name="action" value="timber_avif_settings" />

			<div class="tavif-block">
				<h3><?php esc_html_e('Format and quality', 'timber-avif'); ?></h3>

				<div class="tavif-field">
					<label class="tavif-field-label" for="tavif-format"><?php esc_html_e('Format', 'timber-avif'); ?></label>
					<div class="tavif-field-input">
						<select name="format_mode" id="tavif-format">
							<?php foreach (['auto' => __('Auto', 'timber-avif'), 'avif' => 'AVIF', 'webp' => 'WebP', 'off' => __('None', 'timber-avif')] as $k => $label) : ?>
								<option value="<?php echo esc_attr($k); ?>" <?php selected($s['format_mode'], $k); ?>><?php echo esc_html($label); ?></option>
							<?php endforeach; ?>
						</select><?php echo $default('format_mode'); ?>
						<p class="tavif-hint"><?php esc_html_e('Served instead of the original, which stays as a fallback. Auto picks AVIF when the server can encode it, WebP otherwise.', 'timber-avif'); ?></p>
					</div>
				</div>

				<?php foreach (['avif_quality' => ['AVIF', 1], 'webp_quality' => ['WebP', 1], 'jpeg_quality' => ['JPEG', 60]] as $key => [$label, $min]) :
					$muted = $format && $key !== 'jpeg_quality' && $key !== $format . '_quality'; ?>
					<div class="tavif-field<?php echo $muted ? ' is-muted' : ''; ?>">
						<label class="tavif-field-label" for="tavif-<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label>
						<div class="tavif-field-input">
							<div class="tavif-range">
								<input type="range" id="tavif-<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" min="<?php echo (int) $min; ?>" max="100" value="<?php echo esc_attr($s[$key]); ?>" data-tavif-range />
								<span class="range-val"><?php echo esc_html($s[$key]); ?></span><?php echo $default($key); ?>
							</div>
						</div>
					</div>
				<?php endforeach; ?>
				<p class="tavif-hint tavif-hint--block"><?php esc_html_e('Quality scales are not comparable across formats: AVIF 75 already matches JPEG 95. The JPEG is only the fallback, for browsers without the modern format. Changing the quality of the served format re-encodes the library in the background; the current files are served until each is replaced.', 'timber-avif'); ?></p>
			</div>

			<div class="tavif-block">
				<h3><?php esc_html_e('Widths', 'timber-avif'); ?></h3>
				<p class="tavif-block-intro"><?php esc_html_e('The sizes every image is generated in. The browser picks the one that fits the screen.', 'timber-avif'); ?></p>

				<div class="tavif-field">
					<label class="tavif-field-label" for="tavif-widths"><?php esc_html_e('Sizes', 'timber-avif'); ?></label>
					<div class="tavif-field-input">
						<input type="text" id="tavif-widths" name="breakpoint_widths" value="<?php echo esc_attr($s['breakpoint_widths']); ?>" class="regular-text" /><?php echo $default('breakpoint_widths'); ?>
						<p class="tavif-hint"><?php esc_html_e('Comma-separated. Each size is one more file per image, built at upload. A size added here is built for the existing library in the background.', 'timber-avif'); ?></p>
					</div>
				</div>
			</div>

			<div class="tavif-block">
				<h3><?php esc_html_e('Content', 'timber-avif'); ?></h3>

				<div class="tavif-field">
					<label class="tavif-field-label"><?php esc_html_e('Editor images', 'timber-avif'); ?></label>
					<div class="tavif-field-input">
						<label class="tavif-toggle">
							<input type="hidden" name="content_images" value="0" />
							<input type="checkbox" name="content_images" value="1" <?php checked($s['content_images']); ?> />
							<span class="slider"></span>
							<span class="toggle-label"><?php esc_html_e('Serve images in post content and WYSIWYG fields in the modern format too', 'timber-avif'); ?></span>
						</label>
						<p class="tavif-hint"><?php esc_html_e('The <img> WordPress writes is wrapped in a <picture> that makes no box of its own, so the layout does not change. Cropped sizes such as thumbnails are left as they are.', 'timber-avif'); ?></p>
					</div>
				</div>
			</div>

			<div class="tavif-block">
				<h3><?php esc_html_e('Limits', 'timber-avif'); ?></h3>

				<div class="tavif-field">
					<label class="tavif-field-label" for="tavif-upload"><?php esc_html_e('Upload', 'timber-avif'); ?></label>
					<div class="tavif-field-input">
						<input type="number" id="tavif-upload" name="max_upload_dimension" value="<?php echo esc_attr($s['max_upload_dimension']); ?>" min="1024" step="1" /> px<?php echo $default('max_upload_dimension'); ?>
						<p class="tavif-hint"><?php esc_html_e('Wider images are scaled down to this size on arrival.', 'timber-avif'); ?></p>
					</div>
				</div>

				<div class="tavif-field">
					<label class="tavif-field-label" for="tavif-maxdim"><?php esc_html_e('Conversion', 'timber-avif'); ?></label>
					<div class="tavif-field-input">
						<input type="number" id="tavif-maxdim" name="max_dimension" value="<?php echo esc_attr($s['max_dimension']); ?>" min="512" step="1" /> px
						<input type="number" name="max_file_size" value="<?php echo esc_attr($s['max_file_size']); ?>" min="1" step="1" /> MB<?php echo $default('max_dimension') ?: $default('max_file_size'); ?>
						<p class="tavif-hint"><?php esc_html_e('Conversions start from the full-size file. Past either value they start from the widest generated size instead, so a large PNG, which WordPress does not scale, still gets a modern copy without exhausting server memory.', 'timber-avif'); ?></p>
					</div>
				</div>

				<div class="tavif-field">
					<label class="tavif-field-label"><?php esc_html_e('Discard', 'timber-avif'); ?></label>
					<div class="tavif-field-input">
						<label class="tavif-toggle">
							<input type="hidden" name="only_if_smaller" value="0" />
							<input type="checkbox" name="only_if_smaller" value="1" <?php checked($s['only_if_smaller']); ?> />
							<span class="slider"></span>
							<span class="toggle-label"><?php esc_html_e('Discard the converted file when it weighs meaningfully more than the original', 'timber-avif'); ?></span>
						</label>
						<p class="tavif-hint"><?php esc_html_e('A few KB over is kept — the better format is worth it. Discarded past +10% and +4 KB, or past +50 KB whatever the ratio.', 'timber-avif'); ?></p>
					</div>
				</div>
			</div>

			<p class="tavif-hint"><?php esc_html_e('Only values that differ from the default are saved, so the others follow the defaults of future versions.', 'timber-avif'); ?></p>
			<p class="submit">
				<?php submit_button(__('Save', 'timber-avif'), 'primary', 'save', false); ?>
				<?php submit_button(__('Reset to defaults', 'timber-avif'), 'secondary', 'reset', false, ['onclick' => "return confirm('" . esc_js(__('Reset every setting to its default?', 'timber-avif')) . "');"]); ?>
			</p>
		</form>
		<?php
	}

	private static function render_tools(array $s): void {
		$tools = [
			'rebuild' => [__('Rebuild everything', 'timber-avif'), __('Encode every image again with the current settings — after upgrading the server\'s image libraries, for instance. The current files are served until each is replaced.', 'timber-avif'), __('Rebuild', 'timber-avif'), __('Re-encode the whole library in the background?', 'timber-avif')],
			'clear_cache' => [__('Clear cache', 'timber-avif'), __('Detect the conversion engines available on this server again, and retry the conversions that failed.', 'timber-avif'), __('Clear', 'timber-avif'), ''],
			'purge' => [__('Delete conversions', 'timber-avif'), __('Deletes every AVIF and WebP file and every crop Timber AVIF made. Originals are untouched; the files are rebuilt in the background, and until then the originals are served.', 'timber-avif'), __('Delete', 'timber-avif'), __('Delete every generated AVIF and WebP file?', 'timber-avif')],
		];
		// Only while v6's files may still be on disk.
		if (get_option(Plugin::V6_LEFTOVERS)) $tools['purge_v6'] = Migration\V6::tool();
		?>
		<div class="tavif-tools-grid">
			<div class="tavif-tool-card">
				<h3><?php esc_html_e('Queue', 'timber-avif'); ?></h3>
				<p><?php printf(esc_html__('%s images waiting to be converted.', 'timber-avif'), '<span id="tavif-remaining">' . esc_html(number_format_i18n($s['pending'])) . '</span>'); ?></p>
				<button type="button" id="tavif-work" class="button button-primary" data-total="<?php echo (int) $s['pending']; ?>"<?php disabled(!$s['pending'] || !$s['format']); ?>><?php esc_html_e('Process now', 'timber-avif'); ?></button>
				<div id="tavif-work-progress" class="tavif-progress" hidden>
					<div class="tavif-progress-track"><div class="tavif-progress-bar"></div></div>
					<p class="description tavif-progress-status"></p>
				</div>
			</div>
			<?php foreach ($tools as $key => $tool) : [$title, $text, $button, $confirm] = $tool; $option = $tool[4] ?? ''; ?>
				<div class="tavif-tool-card">
					<h3><?php echo esc_html($title); ?></h3>
					<p><?php echo esc_html($text); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('timber_avif_tools'); ?>
						<input type="hidden" name="action" value="timber_avif_tools" />
						<input type="hidden" name="subaction" value="<?php echo esc_attr($key); ?>" />
						<?php if ($option) : ?>
							<label class="tavif-tool-option"><input type="checkbox" name="timber_resizes" value="1" /> <?php echo esc_html($option); ?></label>
						<?php endif; ?>
						<button type="submit" class="button<?php echo str_starts_with($key, 'purge') ? ' tavif-danger' : ''; ?>"<?php if ($confirm) : ?> onclick="return confirm('<?php echo esc_js($confirm); ?>');"<?php endif; ?>><?php echo esc_html($button); ?></button>
					</form>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_issues(): void {
		$page = max(1, (int) ($_GET['paged'] ?? 1));
		$query = new \WP_Query([
			'post_type'      => 'attachment',
			'post_status'    => 'any',
			'meta_key'       => Index::ISSUE,
			'posts_per_page' => 50,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'DESC',
		]);

		if (!$query->posts) {
			echo '<p class="description">' . esc_html__('No images need a look.', 'timber-avif') . '</p>';
			return;
		}
		?>
		<p class="tavif-block-intro"><?php esc_html_e('Images the worker could not convert, with the reason. A conversion discarded for weighing more than its original is not listed: that is the tolerance at work.', 'timber-avif'); ?></p>
		<table class="tavif-log-table">
			<thead>
				<tr>
					<th></th>
					<th><?php esc_html_e('File', 'timber-avif'); ?></th>
					<th><?php esc_html_e('Reason', 'timber-avif'); ?></th>
					<th><?php esc_html_e('Time', 'timber-avif'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($query->posts as $post) :
					$issue = (array) get_post_meta($post->ID, Index::ISSUE, true); ?>
					<tr>
						<td class="tavif-log-thumb"><?php echo wp_get_attachment_image($post->ID, [40, 40]); ?></td>
						<td class="tavif-log-file"><a href="<?php echo esc_url(get_edit_post_link($post->ID) ?: ''); ?>"><?php echo esc_html(wp_basename((string) get_attached_file($post->ID))); ?></a></td>
						<td class="tavif-log-reason"><?php echo esc_html($issue['text'] ?? ''); ?></td>
						<td class="tavif-log-time"><?php echo esc_html(!empty($issue['at']) ? wp_date('Y-m-d H:i', (int) $issue['at']) : ''); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ($query->max_num_pages > 1) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([
				'base'    => add_query_arg('paged', '%#%', self::url(['tab' => 'issues'])),
				'current' => $page,
				'total'   => $query->max_num_pages,
			]) . '</div></div>';
		}
	}

	private static function render_notices(): void {
		$notices = [
			'updated'      => __('Settings saved.', 'timber-avif'),
			'reset'        => __('Settings reset to their defaults.', 'timber-avif'),
			'rebuild'      => __('The library will be re-encoded in the background.', 'timber-avif'),
			'cleared'      => __('Cache cleared.', 'timber-avif'),
		];
		foreach ($notices as $key => $text) {
			if (!empty($_GET[$key])) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($text) . '</p></div>';
		}
		if (isset($_GET['purged'])) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('%d files deleted.', 'timber-avif'), (int) $_GET['purged'])) . '</p></div>';
		}

		$format = Config::format();
		if (Config::get('format_mode') !== 'off' && !$format) {
			echo '<div class="notice notice-warning"><p>' . esc_html__('This server can encode neither AVIF nor WebP: images are served in their original format.', 'timber-avif') . '</p></div>';
		} elseif ($format && !Engine::can($format)) {
			echo '<div class="notice notice-warning"><p>' . esc_html(sprintf(__('This server cannot generate %s, which is the format selected: nothing is being converted.', 'timber-avif'), strtoupper($format))) . '</p></div>';
		} elseif ($format === 'webp' && Config::get('format_mode') === 'auto') {
			echo '<div class="notice notice-info"><p>' . esc_html__('This server cannot generate AVIF. Images are served as WebP or in their original format.', 'timber-avif') . '</p></div>';
		}
	}

	/* ─────────────────────────────────────────────
	 * Handlers
	 * ───────────────────────────────────────────── */

	public static function handle_settings(): void {
		if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions', 'timber-avif'));
		check_admin_referer('timber_avif_settings');

		if (isset($_POST['reset'])) {
			Config::reset();
			$flag = 'reset';
		} else {
			Config::save(wp_unslash($_POST));
			$flag = 'updated';
		}

		// A new format, quality or width changes the fingerprint: the library is pending again.
		delete_transient(self::STATS);
		Worker::hint();
		Worker::wake();

		wp_safe_redirect(self::url([$flag => 1]));
		exit;
	}

	public static function handle_tools(): void {
		if (!current_user_can('manage_options')) wp_die(esc_html__('Insufficient permissions', 'timber-avif'));
		check_admin_referer('timber_avif_tools');

		$args = ['tab' => 'tools'];
		switch (sanitize_key($_POST['subaction'] ?? '')) {
			case 'rebuild':
				Config::bump_generation();
				$args['rebuild'] = 1;
				break;
			case 'clear_cache':
				Engine::flush();
				self::retry_failures();
				$args['cleared'] = 1;
				break;
			case 'purge':
				$args['purged'] = Tools::purge();
				break;
			case 'purge_v6':
				if (get_option(Plugin::V6_LEFTOVERS)) $args['purged'] = Migration\V6::purge(!empty($_POST['timber_resizes']));
				break;
		}

		delete_transient(self::STATS);
		Worker::hint();
		Worker::wake();

		wp_safe_redirect(self::url($args));
		exit;
	}

	/**
	 * Failed and set-aside images go back in the queue.
	 */
	private static function retry_failures(): void {
		global $wpdb;
		$ids = $wpdb->get_col($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)", Index::ISSUE, Index::ATTEMPTS));
		foreach (array_unique(array_map('intval', $ids)) as $id) {
			delete_post_meta($id, Index::ATTEMPTS);
			delete_post_meta($id, Index::STAMP);
			// A blank key makes the worker look at the entry again, while the renderer keeps
			// treating it as the gap it is: its <source> does not disappear meanwhile.
			$index = Index::get($id);
			foreach (['avif', 'webp'] as $format) {
				foreach ((array) ($index[$format] ?? []) as $file => $entry) {
					if (in_array($entry['skip'] ?? '', ['failed', 'too-large'], true)) {
						$index[$format][$file]['key'] = '';
						$index[$format][$file]['tries'] = 0;
					}
				}
			}
			Index::put($id, $index);
		}
	}

	public static function handle_work(): void {
		check_ajax_referer('timber_avif_work', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error(__('Insufficient permissions', 'timber-avif'), 403);

		$result = Worker::run(Worker::ADMIN_BUDGET);
		delete_transient(self::STATS);
		if ($result['error']) wp_send_json_error($result['error']);
		wp_send_json_success($result);
	}

	/* ─────────────────────────────────────────────
	 * Media library column
	 * ───────────────────────────────────────────── */

	public static function media_column(array $columns): array {
		$new = [];
		foreach ($columns as $k => $v) {
			$new[$k] = $v;
			if ($k === 'date') $new['tavif'] = 'Timber AVIF';
		}
		return $new;
	}

	public static function render_media_column(string $column, int $id): void {
		if ($column !== 'tavif') return;

		$format = Config::format();
		$mime = get_post_mime_type($id);
		if (!$format || !in_array($mime, Config::SOURCE_MIMES, true) || Engine::mime($format) === $mime) {
			echo '<span class="tavif-muted">&mdash;</span>';
			return;
		}

		$index = Index::get($id);
		if (!empty($index['anim'])) {
			echo '<span class="tavif-muted">' . esc_html__('Animated', 'timber-avif') . '</span>';
			return;
		}

		$entries = (array) ($index[$format] ?? []);
		$made = count(array_filter($entries, fn($e) => !empty($e['file'])));
		$discarded = count(array_filter($entries, fn($e) => ($e['skip'] ?? '') === 'larger'));
		$issue = get_post_meta($id, Index::ISSUE, true);

		if (get_post_meta($id, Index::STAMP, true) !== Config::fingerprint()) {
			echo '<span class="tavif-col-badge tavif-col-badge--pending">' . esc_html__('Pending', 'timber-avif') . '</span>';
		} elseif ($issue) {
			echo '<span class="tavif-col-badge tavif-col-badge--fail" title="' . esc_attr($issue['text'] ?? '') . '">' . esc_html__('Issue', 'timber-avif') . '</span>';
		} elseif ($made) {
			$title = $discarded ? sprintf(_n('%d size kept as original: the conversion weighed more', '%d sizes kept as original: the conversion weighed more', $discarded, 'timber-avif'), $discarded) : '';
			echo '<span class="tavif-col-badge tavif-col-badge--' . esc_attr($format) . '" title="' . esc_attr($title) . '">' . esc_html(strtoupper($format) . ' ' . $made . '/' . count($entries)) . '</span>';
		} else {
			echo '<span class="tavif-muted" title="' . esc_attr__('Every conversion weighed more than the original', 'timber-avif') . '">' . esc_html__('Original', 'timber-avif') . '</span>';
		}
	}

	/* ─────────────────────────────────────────────
	 * Numbers
	 * ───────────────────────────────────────────── */

	private static function status(): array {
		global $wpdb;
		$mimes = "'" . implode("','", array_map('esc_sql', Config::SOURCE_MIMES)) . "'";
		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($mimes)");
		$pending = min($total, Worker::count_pending());
		$issues = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s", Index::ISSUE));

		return ['format' => Config::format(), 'total' => $total, 'pending' => $pending, 'issues' => $issues] + self::savings();
	}

	/**
	 * Bytes saved, summed from the indexes a chunk at a time. Cached for ten minutes and
	 * dropped whenever the worker or a tool changes something from this page.
	 */
	private static function savings(): array {
		$cached = get_transient(self::STATS);
		if (is_array($cached)) return $cached;

		global $wpdb;
		$stats = ['files' => 0, 'saved' => 0, 'saved_pct' => 0];
		$format = Config::format();
		if ($format) {
			$bytes = 0;
			$source = 0;
			// The index rows alone, a chunk at a time: loading every attachment's meta for this
			// would pull in all their metadata too.
			$last = 0;
			do {
				$rows = $wpdb->get_results($wpdb->prepare("SELECT meta_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id LIMIT 500", Index::META, $last));
				foreach ($rows as $row) {
					$last = (int) $row->meta_id;
					$index = maybe_unserialize($row->meta_value);
					foreach ((array) ($index[$format] ?? []) as $entry) {
						if (empty($entry['file'])) continue;
						$stats['files']++;
						$bytes += (int) ($entry['bytes'] ?? 0);
						$source += (int) ($entry['src_bytes'] ?? 0);
					}
				}
			} while (count($rows) === 500);
			$stats['saved'] = max(0, $source - $bytes);
			$stats['saved_pct'] = $source > 0 ? (int) round($stats['saved'] / $source * 100) : 0;
		}

		set_transient(self::STATS, $stats, 10 * MINUTE_IN_SECONDS);
		return $stats;
	}

	private static function engine_label(string $engine): string {
		return ['gd' => 'GD', 'imagick' => 'ImageMagick', 'none' => __('Not available', 'timber-avif')][$engine] ?? $engine;
	}

	private static function format_bytes(int $bytes): string {
		return size_format($bytes, 1) ?: '0 B';
	}
}
