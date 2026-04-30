<?php
/**
 * Following module loader — require all classes in dependency order.
 *
 * @package RadicalSocials
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-opml.php';
require_once __DIR__ . '/class-wpcom-oauth.php';
require_once __DIR__ . '/class-wpcom-reader.php';
require_once __DIR__ . '/class-rss-fetcher.php';
require_once __DIR__ . '/class-websub-subscriber.php';
require_once __DIR__ . '/class-activitypub-fetcher.php';
require_once __DIR__ . '/class-feed-fetcher.php';
require_once __DIR__ . '/class-following-rest.php';
require_once __DIR__ . '/class-following.php';
