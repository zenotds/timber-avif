<?php

namespace TimberAVIF;

/**
 * Images in post content and ACF WYSIWYG fields, which the macro never sees: WordPress's
 * <img> is wrapped in a <picture> whose <source> is the attachment's modern set.
 *
 * The <source> comes from the index, so it is complete by the same rule as the macro's:
 * emitted only once every candidate has been processed.
 */
final class Content {

	/**
	 * `wp_content_img_tag`. Runs at 9, before a theme's own filter, which then finds the
	 * <picture> already there and leaves it.
	 */
	public static function img_tag($html, $context, $attachment_id) {
		$id = (int) $attachment_id;
		if (!$id || !is_string($html) || str_contains($html, '<picture') || !Config::get('content_images')) return $html;
		if (!preg_match('/\ssrc="([^"]+)"/', $html, $src)) return $html;

		// Only an image shown uncropped: the modern set is made of full-proportion files, and a
		// cropped sub-size (a thumbnail, a theme's square) would switch aspect ratio in AVIF.
		if (!Sizes::is_proportional((array) wp_get_attachment_metadata($id), wp_basename((string) wp_parse_url($src[1], PHP_URL_PATH)))) return $html;

		$data = Renderer::sources($id);
		if (empty($data['modern'])) return $html;

		if (preg_match('/\ssizes="([^"]*)"/', $html, $m)) {
			$sizes = $m[1];
		} else {
			// No srcset from WordPress: the width attribute is how wide it is shown at most.
			$w = preg_match('/\swidth="(\d+)"/', $html, $m) ? (int) $m[1] : 0;
			$sizes = $w ? "(max-width: {$w}px) 100vw, {$w}px" : '100vw';
		}

		// display: contents, so the wrapper makes no box: margins, floats and align* classes on
		// the <img> behave as they did. Inline, because no stylesheet of ours loads on the front end.
		return '<picture class="tavif-content" style="display:contents"><source type="' . esc_attr($data['modern']['type']) . '" srcset="' . esc_attr($data['modern']['srcset']) . '" sizes="' . esc_attr($sizes) . '">' . $html . '</picture>';
	}
}
