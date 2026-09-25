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
		add_action('wp_ajax_timber_avif_status', [self::class, 'handle_status']);
		add_action('wp_ajax_timber_avif_optimize', [self::class, 'handle_optimize']);
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
				'reading'    => __('Reading templates and content…', 'timber-avif'),
				'deleting'   => __('Deleting…', 'timber-avif'),
				'confirm'    => __('Delete these files? The page cache is purged at the end.', 'timber-avif'),
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
		?>
		<div class="tavif-cards" data-pending="<?php echo (int) $s['pending']; ?>">
			<?php foreach (self::cards($s) as $key => [$label, $value]) : ?>
				<div class="tavif-card-stat" data-tavif-card="<?php echo esc_attr($key); ?>">
					<div class="label"><?php echo esc_html($label); ?></div>
					<div class="value"><?php echo $value; ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * The status cards: label, and the value as markup. Shared with handle_status(), which
	 * the page asks every few seconds while the queue is being worked through.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	private static function cards(array $s): array {
		$format = $s['format'];
		$engine = $format ? Engine::detect($format) : 'none';
		$gd = $engine === 'gd';

		$queue_tip = '';
		if ($s['pending']) {
			$queue_tip = Worker::relay_works() === false
				? __('This server cannot reach itself (a loopback request never arrived: HTTP authentication, or a host that blocks them), so the queue advances with visits and admin requests. Tools → Process now empties it from this browser.', 'timber-avif')
				: __('Converted in the background: each pass starts the next one until the queue is empty. Tools → Process now does it from this browser instead.', 'timber-avif');
		}

		$cards = [
			'format' => [__('Served format', 'timber-avif'), $format ? strtoupper($format) : __('Original', 'timber-avif'), $format ? 'ok' : 'off', ''],
			'engine' => [__('Engine', 'timber-avif'), $format ? self::engine_label($engine) : '—', !$format ? 'off' : ($engine === 'none' ? 'fail' : ($gd ? 'warn' : 'ok')),
				$gd ? __('GD keeps no colour profile: photos in Display P3 or Adobe RGB change colour. Enable Imagick on the server.', 'timber-avif') : ''],
			'library' => [__('Library', 'timber-avif'), sprintf(__('%1$s of %2$s ready', 'timber-avif'), number_format_i18n($s['total'] - $s['pending']), number_format_i18n($s['total'])), $s['pending'] ? 'warn' : 'ok', ''],
			'queue' => [__('Queue', 'timber-avif'), $s['pending'] ? sprintf(_n('%s pending', '%s pending', $s['pending'], 'timber-avif'), number_format_i18n($s['pending'])) : __('Empty', 'timber-avif'), $s['pending'] ? 'warn' : 'ok', $queue_tip],
		];
		if ($s['saved'] > 0) {
			$cards['saved'] = [__('Saved', 'timber-avif'), self::format_bytes($s['saved']), 'ok',
				sprintf(__('%1$s converted files weigh %2$s less than the files they replace (%3$d%%).', 'timber-avif'), number_format_i18n($s['files']), self::format_bytes($s['saved']), $s['saved_pct'])];
		}

		return array_map(fn($c) => [$c[0], '<span class="tavif-dot tavif-dot--' . esc_attr($c[2]) . '"></span>' . esc_html($c[1])
			. ($c[3] ? ' <span class="dashicons dashicons-info-outline" title="' . esc_attr($c[3]) . '"></span>' : '')], $cards);
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
			<?php self::render_optimize(); ?>
			<?php foreach ($tools as $key => [$title, $text, $button, $confirm]) : ?>
				<div class="tavif-tool-card">
					<h3><?php echo esc_html($title); ?></h3>
					<p><?php echo esc_html($text); ?></p>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<?php wp_nonce_field('timber_avif_tools'); ?>
						<input type="hidden" name="action" value="timber_avif_tools" />
						<input type="hidden" name="subaction" value="<?php echo esc_attr($key); ?>" />
						<button type="submit" class="button<?php echo str_starts_with($key, 'purge') ? ' tavif-danger' : ''; ?>"<?php if ($confirm) : ?> onclick="return confirm('<?php echo esc_js($confirm); ?>');"<?php endif; ?>><?php echo esc_html($button); ?></button>
					</form>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Tools → Optimize: start an analysis, see its report, apply it. Each step is a request
	 * of its own (handle_optimize()), and the page reloads when one is done.
	 */
	private static function render_optimize(): void {
		$job = Optimize::job();
		$capped = Optimize::capped();
		$running = $job && ($job['phase'] !== 'done' || (isset($job['apply']) && empty($job['applied'])));
		$report = $job && $job['phase'] === 'done' && empty($job['applied']);
		?>
		<div class="tavif-tool-card tavif-tool-card--wide" id="tavif-optimize">
			<h3><?php esc_html_e('Optimize', 'timber-avif'); ?></h3>
			<p><?php esc_html_e('Deletes the widths no template shows an image at, and files no attachment owns. It reads the theme\'s templates and where each image is placed, and reports first: nothing is deleted until you confirm. An image shown wider later gets its widths back on its own.', 'timber-avif'); ?></p>

			<?php if ($job && !empty($job['applied'])) : ?>
				<p class="tavif-optimize-done"><?php printf(
					/* translators: 1: number of files, 2: size */
					esc_html__('%1$s files deleted, %2$s freed. The page cache was purged.', 'timber-avif'),
					esc_html(number_format_i18n((int) $job['apply']['deleted'])),
					esc_html(self::format_bytes((int) $job['apply']['bytes']))
				); ?></p>
			<?php endif; ?>

			<?php if ($report) : self::render_optimize_report($job); endif; ?>

			<div class="tavif-optimize-actions">
				<?php if ($report) : ?>
					<?php $delete = Optimize::to_delete($job); ?>
					<button type="button" class="button button-primary<?php echo $delete['files'] ? ' tavif-danger-primary' : ''; ?>" data-tavif-optimize="apply"<?php disabled(!$delete['files'] && !$job['plan']); ?>><?php
						/* translators: %s: size */
						echo esc_html($delete['files'] ? sprintf(__('Delete %s', 'timber-avif'), self::format_bytes($delete['bytes'])) : __('Update the limits', 'timber-avif'));
					?></button>
					<?php self::tool_button('optimize_discard', __('Discard the report', 'timber-avif')); ?>
				<?php else : ?>
					<label class="tavif-tool-option"><input type="checkbox" id="tavif-optimize-timber" value="1" /> <?php esc_html_e('Also delete what Timber made next to the originals — resizes (photo-640x0-c-default.jpg), conversions (photo.webp) — and an older version\'s copies: Timber makes again any a template still asks for, on the first view of that page.', 'timber-avif'); ?></label>
					<button type="button" class="button button-primary" data-tavif-optimize="<?php echo $running ? 'resume' : 'start'; ?>"><?php $running ? esc_html_e('Continue the analysis', 'timber-avif') : esc_html_e('Analyse', 'timber-avif'); ?></button>
				<?php endif; ?>
				<?php if ($capped) : ?>
					<?php self::tool_button('optimize_reset', sprintf(
						/* translators: %s: number of images */
						_n('Remove the limit from %s image', 'Remove the limits from %s images', $capped, 'timber-avif'),
						number_format_i18n($capped)
					), __('Every width of these images is made again, in the background. Continue?', 'timber-avif')); ?>
				<?php endif; ?>
			</div>
			<div class="tavif-progress" id="tavif-optimize-progress" hidden>
				<div class="tavif-progress-track"><div class="tavif-progress-bar"></div></div>
				<p class="description tavif-progress-status"></p>
			</div>
		</div>
		<?php
	}

	private static function render_optimize_report(array $job): void {
		$images = $job['images'];
		?>
		<table class="tavif-log-table tavif-optimize-report">
			<thead><tr><th><?php esc_html_e('What', 'timber-avif'); ?></th><th><?php esc_html_e('Files', 'timber-avif'); ?></th><th><?php esc_html_e('Size', 'timber-avif'); ?></th></tr></thead>
			<tbody>
				<?php foreach (Optimize::rows($job) as $key => [$label, $files, $bytes, $deleted]) : ?>
					<tr class="<?php echo $deleted ? '' : 'is-muted'; ?>">
						<td><?php echo esc_html($label); ?><?php if (!$deleted) : ?> <em><?php esc_html_e('(kept)', 'timber-avif'); ?></em><?php endif; ?></td>
						<td><?php echo esc_html(number_format_i18n($files)); ?></td>
						<td><?php echo esc_html(self::format_bytes($bytes)); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php printf(
			/* translators: 1: images, 2: limited, 3: used nowhere, 4: keeping every file */
			esc_html__('%1$s images: %2$s limited to the widths they are shown at, %3$s used nowhere, %4$s keep every file.', 'timber-avif'),
			esc_html(number_format_i18n($images['total'])),
			esc_html(number_format_i18n($images['capped'])),
			esc_html(number_format_i18n($images['unused'])),
			esc_html(number_format_i18n(array_sum($images['kept'])))
		); ?></p>
		<?php if ($images['kept'] || !empty($job['unmatched']) || !empty($job['untraced'])) : ?>
			<details class="tavif-optimize-details">
				<summary><?php esc_html_e('Why some images keep every file', 'timber-avif'); ?></summary>
				<ul>
					<?php foreach ($images['kept'] as $reason => $n) : ?>
						<li><?php echo esc_html(number_format_i18n($n) . ' — ' . (Optimize::reasons()[$reason] ?? $reason)); ?></li>
					<?php endforeach; ?>
				</ul>
				<?php if (!empty($job['unmatched'])) : arsort($job['unmatched']); ?>
					<p><?php esc_html_e('Fields holding images that no template call was traced to:', 'timber-avif'); ?></p>
					<ul class="tavif-code-list">
						<?php foreach (array_slice($job['unmatched'], 0, 15, true) as $place => $n) : ?><li><code><?php echo esc_html($place); ?></code> (<?php echo esc_html(number_format_i18n($n)); ?>)</li><?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<?php if (!empty($job['untraced'])) : ?>
					<p><?php esc_html_e('Template calls whose image could not be followed. An image shown only there gets its widths back on the first view:', 'timber-avif'); ?></p>
					<ul class="tavif-code-list">
						<?php foreach (array_slice($job['untraced'], 0, 15) as $at) : ?><li><code><?php echo esc_html($at); ?></code></li><?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</details>
		<?php endif; ?>
		<?php
	}

	/** A Tools form with one button. */
	private static function tool_button(string $action, string $label, string $confirm = ''): void {
		?>
		<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
			<?php wp_nonce_field('timber_avif_tools'); ?>
			<input type="hidden" name="action" value="timber_avif_tools" />
			<input type="hidden" name="subaction" value="<?php echo esc_attr($action); ?>" />
			<button type="submit" class="button"<?php if ($confirm) : ?> onclick="return confirm('<?php echo esc_js($confirm); ?>');"<?php endif; ?>><?php echo esc_html($label); ?></button>
		</form>
		<?php
	}

	private static function render_issues(): void {
		global $wpdb;
		$page = max(1, (int) ($_GET['paged'] ?? 1));
		$per_page = 50;
		// One row per file, not per attachment: WPML gives each language an attachment of the
		// same file, and they fail together. Not through WP_Query, which WPML narrows to the
		// admin's language while the count covered them all — Issues (12) above three rows.
		$ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
			"SELECT MIN(i.post_id) " . self::issues_from() . " GROUP BY f.meta_value ORDER BY MIN(i.post_id) DESC LIMIT %d OFFSET %d",
			Index::ISSUE,
			$per_page,
			($page - 1) * $per_page
		)));
		$pages = (int) ceil(self::issue_count() / $per_page);

		if (!$ids) {
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
				<?php foreach ($ids as $id) :
					$issue = (array) get_post_meta($id, Index::ISSUE, true); ?>
					<tr>
						<td class="tavif-log-thumb"><?php echo wp_get_attachment_image($id, [40, 40]); ?></td>
						<td class="tavif-log-file"><a href="<?php echo esc_url(get_edit_post_link($id) ?: ''); ?>"><?php echo esc_html(wp_basename((string) get_attached_file($id))); ?></a></td>
						<td class="tavif-log-reason"><?php echo esc_html($issue['text'] ?? ''); ?></td>
						<td class="tavif-log-time"><?php echo esc_html(!empty($issue['at']) ? wp_date('Y-m-d H:i', (int) $issue['at']) : ''); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ($pages > 1) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links([
				'base'    => add_query_arg('paged', '%#%', self::url(['tab' => 'issues'])),
				'current' => $page,
				'total'   => $pages,
			]) . '</div></div>';
		}
	}

	/** Issues of attachments neither trashed nor half-created, each with the file it stands for. */
	private static function issues_from(): string {
		global $wpdb;
		return "FROM {$wpdb->postmeta} i
			JOIN {$wpdb->postmeta} f ON f.post_id = i.post_id AND f.meta_key = '_wp_attached_file'
			JOIN {$wpdb->posts} p ON p.ID = i.post_id AND p.post_status NOT IN ('trash', 'auto-draft')
			WHERE i.meta_key = %s";
	}

	/** Files with an issue: the number on the tab and the rows under it. */
	private static function issue_count(): int {
		global $wpdb;
		return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT f.meta_value) " . self::issues_from(), Index::ISSUE));
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
		if (isset($_GET['unlimited'])) {
			/* translators: %d: number of images */
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('%d images no longer limited: their widths are made again in the background.', 'timber-avif'), (int) $_GET['unlimited'])) . '</p></div>';
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

		Server::ensure();
		$type = $format ? Server::served_type() : null;
		if ($type && $type !== Engine::mime($format)) {
			[$advice, $lines] = Server::advice();
			echo '<div class="notice notice-warning"><p>' . esc_html(sprintf(
				/* translators: 1: format, 2: MIME type the server sends, 3: the right one */
				__('The server sends %1$s files as %2$s instead of %3$s. Browsers show them anyway, but developer tools, PageSpeed and CDNs take them for what the header says.', 'timber-avif'),
				strtoupper($format),
				$type,
				Engine::mime($format)
			)) . '</p><p>' . esc_html($advice) . '</p>' . ($lines ? '<pre><code>' . esc_html($lines) . '</code></pre>' : '') . '</div>';
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
			case 'optimize_discard':
				Optimize::discard();
				break;
			case 'optimize_reset':
				$args['unlimited'] = Optimize::reset();
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

		// The browser drives the queue while this page asks for passes: WP-Cron and the relay
		// stand back, so the two do not take turns with the lock. It lapses soon after the last one.
		set_transient(Worker::DRIVEN, 1, 30);
		$result = Worker::run(Worker::ADMIN_BUDGET);
		if (!$result['remaining']) delete_transient(Worker::DRIVEN);
		delete_transient(self::STATS);
		if ($result['error']) wp_send_json_error($result['error']);
		wp_send_json_success($result);
	}

	/** One step of Optimize: the analysis, or the deletion once the report is confirmed. */
	public static function handle_optimize(): void {
		check_ajax_referer('timber_avif_work', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error(__('Insufficient permissions', 'timber-avif'), 403);

		$step = sanitize_key($_POST['step'] ?? '');
		if ($step === 'start') Optimize::start(!empty($_POST['timber']));
		$job = $step === 'apply' ? Optimize::apply(Worker::ADMIN_BUDGET) : Optimize::analyse(Worker::ADMIN_BUDGET);
		if (($job['error'] ?? '') === 'widths') wp_send_json_error(__('Settings → Widths changed since the analysis: discard the report and run it again.', 'timber-avif'));
		delete_transient(self::STATS);

		wp_send_json_success([
			'done'     => $step === 'apply' ? !empty($job['applied']) : $job['phase'] === 'done',
			'busy'     => !empty($job['busy']),
			'progress' => Optimize::progress($job),
		]);
	}

	/** How far the queue is, for the status cards while it is being worked through. */
	public static function handle_status(): void {
		check_ajax_referer('timber_avif_work', 'nonce');
		if (!current_user_can('manage_options')) wp_send_json_error(__('Insufficient permissions', 'timber-avif'), 403);

		// The two cards that move while the queue does. Savings are summed from every index, so
		// they wait for a reload.
		$status = self::status(false);
		$cards = array_intersect_key(self::cards($status), ['library' => 1, 'queue' => 1]);
		wp_send_json_success(['pending' => $status['pending'], 'cards' => array_map(fn($c) => $c[1], $cards)]);
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

	private static function status(bool $savings = true): array {
		global $wpdb;
		$mimes = "'" . implode("','", array_map('esc_sql', Config::SOURCE_MIMES)) . "'";
		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($mimes)");
		$pending = min($total, Worker::count_pending());
		$issues = self::issue_count();

		return ['format' => Config::format(), 'total' => $total, 'pending' => $pending, 'issues' => $issues]
			+ ($savings ? self::savings() : ['files' => 0, 'saved' => 0, 'saved_pct' => 0]);
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
